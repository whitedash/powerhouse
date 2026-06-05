<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\CommissionLedger;
use App\Models\MaavelusStatement;
use App\Models\Product;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MaavelusStatementService
{
    public function generateCommissions(MaavelusStatement $statement): void
    {
        if ($statement->commissions_generated) {
            throw new RuntimeException('Commissions already generated for this statement.');
        }

        // Eager-load the full attribution chain so the per-line loop can
        // hit it in memory rather than N+1ing across customers.
        $statement->load('lines.customer.referral.referrer.user');

        $maavelus = Product::where('slug', 'maavelus')->first();
        if (! $maavelus) {
            throw new RuntimeException('Maavelus product not seeded.');
        }

        DB::transaction(function () use ($statement, $maavelus) {
            foreach ($statement->lines as $line) {
                $customer = $line->customer;
                if (! $customer) {
                    continue;
                }

                $referral = $customer->referral;
                if (! $referral) {
                    continue;
                }
                $referrer = $referral->referrer;
                if (! $referrer) {
                    continue;
                }

                $rule = app(CommissionRuleResolver::class)
                    ->resolve($referrer->id, $maavelus->id, $statement->period_start, $statement->period_end);
                if (! $rule) {
                    continue;
                }

                $amount = app(CommissionService::class)->calculate($rule, (float) $line->total_fees);
                if ($amount <= 0) {
                    continue;
                }

                CommissionLedger::create([
                    'referrer_id' => $referrer->id,
                    'customer_id' => $line->customer_id,
                    'rule_id' => $rule->id,
                    'product_id' => $maavelus->id,
                    'invoice_id' => null,
                    'trigger_type' => 'monthly_recurring',
                    'gross_amount' => $line->total_fees,
                    'commission_amount' => $amount,
                    'status' => 'pending',
                    'period_start' => $statement->period_start,
                    'period_end' => $statement->period_end,
                ]);
            }

            $statement->update(['commissions_generated' => true]);

            ActivityLog::create([
                'user_id' => auth()->id(),
                'user_role' => auth()->user()?->role,
                'action' => 'maavelus_statement.commissions_generated',
                'entity_type' => 'maavelus_statement',
                'entity_id' => $statement->id,
                'after' => [
                    'period' => $statement->periodLabel(),
                    'total_fees' => (float) $statement->total_fees,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
            ]);
        });
    }

    public function confirm(MaavelusStatement $statement, User $confirmedBy): void
    {
        if (! $statement->isEditable()) {
            throw new RuntimeException('Statement is already confirmed.');
        }

        DB::transaction(function () use ($statement, $confirmedBy) {
            if (! $statement->commissions_generated) {
                $this->generateCommissions($statement);
                $statement->refresh();
            }

            // Generate + persist PDF before flipping status — the act of
            // confirming is what locks the statement, so the user gets a
            // snapshot at confirmation time even if the data changed
            // later (it can't here, but defence in depth).
            $pdfBinary = $this->generatePdf($statement)->output();

            $path = 'maavelus-statements/'.$statement->period_start->format('Y-m').'-statement.pdf';
            Storage::disk('private')->put($path, $pdfBinary);

            $statement->update([
                'status' => 'confirmed',
                'pdf_path' => $path,
                'confirmed_by' => $confirmedBy->id,
                'confirmed_at' => now(),
            ]);

            ActivityLog::create([
                'user_id' => $confirmedBy->id,
                'user_role' => $confirmedBy->role,
                'action' => 'maavelus_statement.confirmed',
                'entity_type' => 'maavelus_statement',
                'entity_id' => $statement->id,
                'after' => [
                    'period' => $statement->periodLabel(),
                    'pdf_path' => $path,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
            ]);
        });
    }

    public function generatePdf(MaavelusStatement $statement): DomPdf
    {
        $statement->load(['lines.customer', 'confirmedBy', 'createdBy']);

        $entity = BillingEntity::where('name', 'like', '%Maavelus%')->first();

        return Pdf::loadView('pdf.maavelus-statement', [
            'statement' => $statement,
            'entity' => $entity,
            'logo_data' => $this->resolveLogoData($entity),
            'commission_totals' => $this->getCommissionTotals($statement),
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'dpi' => 96,
                'defaultFont' => 'Arial',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
            ]);
    }

    /**
     * Rule resolution + commission math now live in the shared
     * CommissionRuleResolver + CommissionService::calculate(), used by both
     * this statement flow and the invoice-paid engine so they compute
     * identically. (Maavelus still writes its own ledger rows here — it is
     * NOT routed through the invoice engine, and is excluded from it via
     * referrals.commission_excluded_slugs — so revenue is never double-counted.)
     *
     * @return array<int, array{referrer_name: string, total: float}>
     */
    private function getCommissionTotals(MaavelusStatement $statement): array
    {
        return CommissionLedger::where('period_start', $statement->period_start)
            ->where('period_end', $statement->period_end)
            ->with('referrer.user')
            ->get()
            ->groupBy('referrer_id')
            ->map(fn ($entries) => [
                'referrer_name' => $entries->first()->referrer->user->name ?? 'Unknown',
                'total' => (float) $entries->sum('commission_amount'),
            ])
            ->values()
            ->all();
    }

    /**
     * Base64-encode the entity's stored logo so dompdf can embed it via
     * `data:` URL (dompdf's chroot rejects absolute filesystem paths
     * outside its vendor dir — same workaround as InvoiceController).
     */
    private function resolveLogoData(?BillingEntity $entity): ?string
    {
        $path = $entity?->logo_path;
        if (! $path) {
            return null;
        }
        $absolute = Storage::disk('private')->path($path);
        if (! file_exists($absolute)) {
            return null;
        }
        $mime = mime_content_type($absolute) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($absolute));
    }
}
