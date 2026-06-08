<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Models\Website;
use App\Support\InvoiceVat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-website hosting billing (Stage 1b). Hosting lives on the website
 * (Stage 1a): plan_id + plan_price_id from the catalog, billed on its own
 * hosting_next_billing_date cadence — NOT via a CustomerProduct.
 *
 * Mirrors GenerateSubscriptionInvoices' cadence (advance the anchor + stamp
 * last-invoiced inside the same transaction) and GenerateDomainRenewalInvoices'
 * asset-billing shape (billing entity from the plan's product). A website must
 * explicitly opt in via hosting_auto_invoice; a suspended/none website is never
 * billed.
 *
 * Idempotency: hosting_next_billing_date is advanced by the price-tier interval
 * inside the invoice transaction, so a re-run on the same day re-selects
 * nothing and never double-bills.
 */
class GenerateHostingInvoices extends Command
{
    /** @var string */
    protected $signature = 'invoices:generate-hosting '
        .'{--dry-run : List which websites would be invoiced without creating any rows}';

    /** @var string */
    protected $description = 'Generate draft hosting invoices for active websites due for billing.';

    private const DEFAULT_PAYMENT_TERM_DAYS = 14;

    /** @var array<int, BillingEntity|null> Per-run cache of entities for VAT. */
    private array $vatEntities = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();

        // Eligible: hosting active, opted into auto-billing, has a catalog
        // plan + tier, and due today or earlier.
        $websites = Website::query()
            ->where('hosting_status', 'active')
            ->where('hosting_auto_invoice', true)
            ->whereNotNull('plan_id')
            ->whereNotNull('plan_price_id')
            ->whereNotNull('hosting_next_billing_date')
            ->where('hosting_next_billing_date', '<=', $today->toDateString())
            ->with([
                'customer:id,name',
                'plan:id,name,product_id',
                'plan.product:id,name,billing_entity_id',
                'planPrice:id,plan_id,price,interval_count,interval_unit,label',
            ])
            ->get();

        if ($websites->isEmpty()) {
            $this->info('No websites due for hosting invoicing today.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d website(s) due for hosting invoicing.', $websites->count()));

        $fallbackEntityId = BillingEntity::where('is_active', true)->orderBy('id')->value('id');

        // invoices.created_by is NOT NULL; a system-generated invoice is
        // attributed to a super_admin (else the first user).
        $systemUserId = User::where('role', 'super_admin')->orderBy('id')->value('id')
            ?? User::orderBy('id')->value('id');
        if ($systemUserId === null) {
            $this->error('No user to attribute system invoices to — aborting.');

            return self::FAILURE;
        }

        $generated = 0;
        $skipped = 0;

        foreach ($websites as $website) {
            try {
                // Defensive: never bill a website that isn't actively hosted,
                // even if the select above ever loosens.
                if ($website->hosting_status !== 'active') {
                    $skipped++;

                    continue;
                }

                $tier = $website->planPrice;
                $plan = $website->plan;
                if ($tier === null || $plan === null) {
                    $this->error(sprintf('Skipped %s — missing hosting plan/tier.', $website->name));
                    $skipped++;

                    continue;
                }

                $price = (float) $tier->price;
                if ($price <= 0) {
                    $this->error(sprintf('Skipped %s — hosting price resolves to £0.', $website->name));
                    $skipped++;

                    continue;
                }

                $entityId = $plan->product->billing_entity_id ?? $fallbackEntityId;
                if ($entityId === null) {
                    $this->error(sprintf('Skipped %s — no billing entity on the plan product.', $website->name));
                    $skipped++;

                    continue;
                }

                $description = sprintf(
                    'Hosting — %s (%s · %s)',
                    $website->name,
                    $plan->name,
                    $tier->interval_label,
                );

                if ($dryRun) {
                    $this->line(sprintf('[dry-run] %s — £%s · %s', $website->name, number_format($price, 2), $description));
                    $generated++;

                    continue;
                }

                // VAT from the issuing entity's single source of truth — a
                // non-registered entity yields zero VAT (no hardcoded rate).
                $vat = InvoiceVat::breakdown($price, $this->vatEntity($entityId));

                DB::transaction(function () use ($website, $plan, $tier, $entityId, $price, $vat, $description, $today, $systemUserId): void {
                    $dueDate = $today->copy()->addDays(self::DEFAULT_PAYMENT_TERM_DAYS);

                    $invoice = Invoice::create([
                        'number' => Invoice::generateNextNumber(),
                        'customer_id' => $website->customer_id,
                        'billing_entity_id' => $entityId,
                        'type' => 'subscription',
                        'status' => 'draft',
                        'subtotal' => $price,
                        'vat_rate' => $vat['vat_rate'],
                        'vat_amount' => $vat['vat_amount'],
                        'total' => $vat['total'],
                        'amount_paid' => 0,
                        'issue_date' => $today->toDateString(),
                        'due_date' => $dueDate->toDateString(),
                        'notes' => 'Auto-generated hosting invoice for '.$website->name.'.',
                        'created_by' => $systemUserId,
                    ]);

                    InvoiceLine::create([
                        'invoice_id' => $invoice->id,
                        'product_id' => $plan->product_id,
                        'plan_id' => $plan->id,
                        'description' => $description,
                        'quantity' => 1,
                        'unit_price' => $price,
                        'amount' => $price,
                        'sort_order' => 0,
                    ]);

                    // Advance the cadence + stamp last-invoiced BEFORE COMMIT so
                    // a re-run today re-selects nothing (idempotent).
                    $next = $this->advance(
                        $website->hosting_next_billing_date ?? $today,
                        (int) $tier->interval_count,
                        (string) $tier->interval_unit,
                    );
                    $website->update([
                        'hosting_next_billing_date' => $next,
                        'hosting_last_invoiced_at' => $today->toDateString(),
                    ]);

                    // MRR aggregates are cached; bust so the next page reflects
                    // today's billing.
                    Cache::forget('dash.mrr');

                    ActivityLog::create([
                        'user_id' => null,
                        'user_role' => 'system',
                        'action' => 'invoice.hosting_generated',
                        'entity_type' => 'invoice',
                        'entity_id' => $invoice->id,
                        'before' => null,
                        'after' => [
                            'number' => $invoice->number,
                            'customer_id' => $invoice->customer_id,
                            'website_id' => $website->id,
                            'website' => $website->name,
                            'amount' => $vat['total'],
                        ],
                        'ip_address' => null,
                        'user_agent' => 'artisan:invoices:generate-hosting',
                    ]);

                    $this->info(sprintf('Generated %s for %s — £%s', $invoice->number, $website->name, number_format($vat['total'], 2)));
                });

                $generated++;
            } catch (Throwable $e) {
                $this->error(sprintf('Failed website #%d (%s): %s', $website->id, $website->name, $e->getMessage()));
                $skipped++;
            }
        }

        $this->info(sprintf(
            '%d hosting invoice%s generated, %d skipped.%s',
            $generated,
            $generated === 1 ? '' : 's',
            $skipped,
            $dryRun ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Resolve (and memoise) the billing entity used to compute VAT — fetched
     * once per entity rather than per invoice.
     */
    private function vatEntity(int $entityId): ?BillingEntity
    {
        return $this->vatEntities[$entityId] ??= BillingEntity::find($entityId);
    }

    private function advance(Carbon $from, int $count, string $unit): Carbon
    {
        $count = max(1, $count);

        return match ($unit) {
            'day' => $from->copy()->addDays($count),
            'week' => $from->copy()->addWeeks($count),
            'month' => $from->copy()->addMonthsNoOverflow($count),
            'year' => $from->copy()->addYearsNoOverflow($count),
            default => $from->copy()->addMonthNoOverflow(),
        };
    }
}
