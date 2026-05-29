<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\MaavelusStatement;
use App\Models\MaavelusStatementLine;
use App\Services\MaavelusStatementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MaavelusStatementController extends Controller
{
    public function index(): Response
    {
        $statements = MaavelusStatement::with('createdBy', 'confirmedBy')
            ->orderByDesc('period_start')
            ->get()
            ->map(fn (MaavelusStatement $s) => [
                'id' => $s->id,
                'period_label' => $s->periodLabel(),
                'period_start' => $s->period_start?->toDateString(),
                'period_end' => $s->period_end?->toDateString(),
                'total_fees' => (float) $s->total_fees,
                'total_orders' => $s->total_orders,
                'status' => $s->status,
                'commissions_generated' => $s->commissions_generated,
                'confirmed_at' => $s->confirmed_at?->toIso8601String(),
                'confirmed_by_name' => $s->confirmedBy?->name,
                'data_source' => $s->data_source,
                'download_url' => route('internal.maavelus-statements.download', $s->id),
            ])
            ->all();

        $maavelusCustomers = Customer::whereNull('archived_at')
            ->whereHas('customerProducts', function ($q) {
                $q->where('status', 'active')
                    ->whereHas('product', fn ($p) => $p->where('slug', 'maavelus'));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'city'])
            ->all();

        return Inertia::render('Internal/Maavelus/Statements/Index', [
            'statements' => $statements,
            'maavelus_customers' => $maavelusCustomers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period_month' => ['required', 'date_format:Y-m'],
            'total_orders' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.customer_id' => ['required', 'integer', 'exists:customers,id'],
            'lines.*.total_fees' => ['required', 'numeric', 'min:0.01'],
            'lines.*.order_count' => ['nullable', 'integer', 'min:0'],
        ]);

        $periodStart = Carbon::parse($data['period_month'].'-01')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        if (MaavelusStatement::where('period_start', $periodStart->toDateString())->exists()) {
            return back()->withErrors([
                'period_month' => 'A statement already exists for '.$periodStart->format('F Y').'.',
            ])->withInput();
        }

        $userId = $request->user()->id;

        $statement = DB::transaction(function () use ($data, $request, $periodStart, $periodEnd, $userId) {
            $totalFees = collect($data['lines'])->sum(fn ($l) => (float) $l['total_fees']);

            $statement = MaavelusStatement::create([
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_fees' => $totalFees,
                'total_orders' => $data['total_orders'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'data_source' => 'manual',
                'created_by' => $userId,
            ]);

            foreach ($data['lines'] as $line) {
                MaavelusStatementLine::create([
                    'statement_id' => $statement->id,
                    'customer_id' => $line['customer_id'],
                    'total_fees' => $line['total_fees'],
                    'order_count' => $line['order_count'] ?? null,
                ]);
            }

            $this->logActivity($request, 'maavelus_statement.created', $statement, after: [
                'period' => $statement->periodLabel(),
                'total_fees' => (float) $statement->total_fees,
                'lines' => count($data['lines']),
            ]);

            return $statement;
        });

        return redirect()
            ->route('internal.maavelus-statements.show', $statement->id)
            ->with('success', 'Statement for '.$statement->periodLabel().' created as draft.');
    }

    public function show(int $id): Response
    {
        $statement = MaavelusStatement::with([
            'lines.customer.referral.referrer.user',
            'createdBy',
            'confirmedBy',
        ])->findOrFail($id);

        $commissions = CommissionLedger::where('period_start', $statement->period_start)
            ->where('period_end', $statement->period_end)
            ->with('referrer.user', 'customer')
            ->get()
            ->map(fn (CommissionLedger $c) => [
                'id' => $c->id,
                'referrer_name' => $c->referrer->user->name ?? 'Unknown',
                'customer_name' => $c->customer->name ?? 'Unknown',
                'gross_amount' => (float) $c->gross_amount,
                'commission_amount' => (float) $c->commission_amount,
                'status' => $c->status,
            ])
            ->all();

        return Inertia::render('Internal/Maavelus/Statements/Show', [
            'statement' => [
                'id' => $statement->id,
                'period_label' => $statement->periodLabel(),
                'period_start' => $statement->period_start?->toDateString(),
                'period_end' => $statement->period_end?->toDateString(),
                'total_fees' => (float) $statement->total_fees,
                'total_orders' => $statement->total_orders,
                'status' => $statement->status,
                'data_source' => $statement->data_source,
                'commissions_generated' => $statement->commissions_generated,
                'notes' => $statement->notes,
                'confirmed_at' => $statement->confirmed_at?->toIso8601String(),
                'confirmed_by_name' => $statement->confirmedBy?->name,
                'created_at' => $statement->created_at?->toIso8601String(),
                'created_by_name' => $statement->createdBy?->name,
                'lines' => $statement->lines->map(fn (MaavelusStatementLine $l) => [
                    'id' => $l->id,
                    'customer_id' => $l->customer_id,
                    'customer_name' => $l->customer ? $l->customer->name : 'Unknown',
                    'total_fees' => (float) $l->total_fees,
                    'order_count' => $l->order_count,
                ])->all(),
                'download_url' => route('internal.maavelus-statements.download', $statement->id),
            ],
            'commissions' => $commissions,
        ]);
    }

    public function confirm(int $id, Request $request, MaavelusStatementService $service): RedirectResponse
    {
        $statement = MaavelusStatement::findOrFail($id);

        if (! $statement->isEditable()) {
            return back()->with('error', 'Statement is already confirmed.');
        }

        try {
            $service->confirm($statement, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('internal.maavelus-statements.show', $id)
            ->with('success', 'Statement confirmed and commissions generated for '.$statement->fresh()->periodLabel().'.');
    }

    public function download(int $id, MaavelusStatementService $service): SymfonyResponse
    {
        $statement = MaavelusStatement::findOrFail($id);

        // Persisted PDF for confirmed statements — preserves the exact
        // snapshot the user signed off on. Draft statements re-render
        // every time so the preview reflects the latest edits.
        if ($statement->pdf_path && Storage::disk('private')->exists($statement->pdf_path)) {
            return Storage::disk('private')->download(
                $statement->pdf_path,
                'maavelus-'.$statement->period_start->format('Y-m').'-statement.pdf',
            );
        }

        return $service->generatePdf($statement)->stream(
            'maavelus-'.$statement->period_start->format('Y-m').'-statement.pdf',
        );
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $statement = MaavelusStatement::findOrFail($id);

        if (! $statement->isEditable()) {
            return back()->with('error', 'Confirmed statements cannot be deleted.');
        }

        DB::transaction(function () use ($statement, $request) {
            $period = $statement->periodLabel();

            $statement->lines()->delete();

            $this->logActivity($request, 'maavelus_statement.deleted', $statement, before: [
                'period' => $period,
            ]);

            $statement->delete();
        });

        return redirect()
            ->route('internal.maavelus-statements.index')
            ->with('success', 'Statement deleted.');
    }

    private function logActivity(
        Request $request,
        string $action,
        MaavelusStatement $statement,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'maavelus_statement',
            'entity_id' => $statement->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
