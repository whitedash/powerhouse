<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Referrer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReferrerController extends Controller
{
    /* ─────────────────────────────────────────────────────────────────────
     * INDEX
     * ─────────────────────────────────────────────────────────────────── */

    public function index(): Response
    {
        $monthStart = now()->startOfMonth();

        // Each referrer carries three rolling commission totals computed
        // straight off commission_ledger — sums beat caching here because
        // status mutations bust them anyway.
        $referrers = Referrer::with('user')
            ->withCount('referrals as customer_count')
            ->where('is_active', true)
            ->get()
            ->map(function (Referrer $r) use ($monthStart): array {
                $user = $r->user;
                $rules = CommissionRule::with('product')
                    ->where('referrer_id', $r->id)
                    ->where('is_active', true)
                    ->get();

                $pendingMonths = (int) CommissionLedger::where('referrer_id', $r->id)
                    ->where('status', 'pending')
                    ->selectRaw('COUNT(DISTINCT DATE_FORMAT(created_at, "%Y-%m")) as months')
                    ->value('months');

                return [
                    'id' => $r->id,
                    'name' => $user ? $user->name : 'Unknown',
                    'email' => $user ? $user->email : '',
                    'avatar_colour' => $user?->avatar_colour,
                    'member_since' => $user && $user->created_at ? $user->created_at->toIso8601String() : null,
                    'customer_count' => $r->customer_count,
                    'is_active' => $r->is_active,
                    'this_month' => (float) CommissionLedger::where('referrer_id', $r->id)
                        ->where('created_at', '>=', $monthStart)
                        ->sum('commission_amount'),
                    'pending_payout' => (float) CommissionLedger::where('referrer_id', $r->id)
                        ->where('status', 'pending')
                        ->sum('commission_amount'),
                    'pending_months' => $pendingMonths,
                    'all_time' => (float) CommissionLedger::where('referrer_id', $r->id)
                        ->whereIn('status', ['approved', 'paid'])
                        ->sum('commission_amount'),
                    'commission_models' => $rules->map(fn (CommissionRule $rule): array => [
                        'product_slug' => $rule->product?->slug,
                        'product_name' => $rule->product?->name,
                        'type' => $rule->type,
                        'copy' => $this->describeRule($rule),
                    ])->values()->all(),
                ];
            })
            ->sortByDesc(fn (array $r): float => $r['pending_payout'])
            ->values();

        // Flag the highest-volume referrer so the UI can render the
        // "Top referrer" pin without an extra round-trip per row.
        $top = $referrers->sortByDesc(fn (array $r): int => $r['customer_count'])->first();
        $topId = is_array($top) ? $top['id'] : null;
        $referrers = $referrers->map(fn (array $r): array => [...$r, 'is_top' => $r['id'] === $topId]);

        $ledger = CommissionLedger::with([
            'referrer.user:id,name,avatar_colour',
            'customer:id,name',
            'product:id,name,slug',
            'rule',
        ])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(function (CommissionLedger $entry): array {
                $ref = $entry->referrer;
                $refUser = $ref?->user;

                return [
                    'id' => $entry->id,
                    'trigger_type' => $entry->trigger_type,
                    'status' => $entry->status,
                    'gross_amount' => (float) $entry->gross_amount,
                    'commission_amount' => (float) $entry->commission_amount,
                    'period_start' => $entry->period_start?->toIso8601String(),
                    'period_end' => $entry->period_end?->toIso8601String(),
                    'approved_at' => $entry->approved_at?->toIso8601String(),
                    'paid_at' => $entry->paid_at?->toIso8601String(),
                    'created_at' => $entry->created_at?->toIso8601String(),
                    'referrer' => $ref ? [
                        'id' => $ref->id,
                        'name' => $refUser?->name,
                        'avatar_colour' => $refUser?->avatar_colour,
                    ] : null,
                    'customer' => $entry->customer ? [
                        'id' => $entry->customer->id,
                        'name' => $entry->customer->name,
                    ] : null,
                    'product' => $entry->product ? [
                        'id' => $entry->product->id,
                        'name' => $entry->product->name,
                        'slug' => $entry->product->slug,
                    ] : null,
                    'description' => $this->describeLedgerEntry($entry),
                ];
            });

        $pendingTotal = (float) CommissionLedger::where('status', 'pending')->sum('commission_amount');

        // Per-referrer breakdown of the pending bucket — feeds the
        // amber banner below the ledger. Use DB::table for the aggregate
        // join so we don't hydrate Eloquent rows just to discard them.
        $pendingBreakdown = DB::table('commission_ledger')
            ->join('referrers', 'commission_ledger.referrer_id', '=', 'referrers.id')
            ->join('users', 'referrers.user_id', '=', 'users.id')
            ->where('commission_ledger.status', 'pending')
            ->groupBy('referrers.id', 'users.name')
            ->selectRaw('referrers.id as referrer_id, users.name as name, SUM(commission_amount) as amount')
            ->get()
            ->map(fn (object $row): array => [
                'referrer_id' => (int) $row->referrer_id,
                'name' => (string) $row->name,
                'amount' => (float) $row->amount,
            ])
            ->all();

        return Inertia::render('Internal/Referrers/Index', [
            'referrers' => $referrers,
            'ledger' => $ledger,
            'pending_total' => $pendingTotal,
            'pending_breakdown' => $pendingBreakdown,
            'all_time_total' => (float) CommissionLedger::whereIn('status', ['approved', 'paid'])
                ->sum('commission_amount'),
            'total_customers' => $referrers->sum('customer_count'),
            'pending_count' => CommissionLedger::where('status', 'pending')->count(),
        ]);
    }

    /* ─────────────────────────────────────────────────────────────────────
     * MUTATIONS
     * ─────────────────────────────────────────────────────────────────── */

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'commission_note' => ['nullable', 'string', 'max:500'],
        ]);

        $tempPassword = Str::random(16);

        $referrer = DB::transaction(function () use ($data, $request, $tempPassword) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => 'referrer',
                'password' => Hash::make($tempPassword),
            ]);

            $referrer = Referrer::create([
                'user_id' => $user->id,
                'is_active' => true,
            ]);

            $this->logActivity($request, 'referrer.created', 'referrer', $referrer->id, after: [
                'name' => $data['name'],
                'email' => $data['email'],
                'commission_note' => $data['commission_note'] ?? null,
            ]);

            return $referrer;
        });

        return back()->with([
            'success' => "{$data['name']} added as a referrer.",
            'temp_password' => $tempPassword,
        ]);
    }

    public function approveCommission(int $id, Request $request): RedirectResponse
    {
        $entry = CommissionLedger::findOrFail($id);

        if ($entry->status !== 'pending') {
            return back()->with('error', 'This entry is not pending approval.');
        }

        DB::transaction(function () use ($entry, $request) {
            $before = ['status' => $entry->status];
            $entry->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            $this->logActivity(
                $request,
                'commission.approved',
                'commission_ledger',
                $entry->id,
                $before,
                ['status' => 'approved', 'amount' => $entry->commission_amount],
            );

            Cache::forget('nav.invoices_outstanding');
        });

        return back()->with('success', 'Commission entry approved.');
    }

    public function approveAll(Request $request): RedirectResponse
    {
        $referrerId = $request->integer('referrer_id') ?: null;

        $query = CommissionLedger::where('status', 'pending');
        if ($referrerId) {
            $query->where('referrer_id', $referrerId);
        }

        $count = (clone $query)->count();
        $total = (float) (clone $query)->sum('commission_amount');

        if ($count === 0) {
            return back()->with('error', 'No pending entries to approve.');
        }

        DB::transaction(function () use ($query, $request, $count, $total) {
            (clone $query)->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            $this->logActivity(
                $request,
                'commission.bulk_approved',
                'commission_ledger',
                0,
                after: ['count' => $count, 'total' => $total],
            );

            Cache::forget('nav.invoices_outstanding');
        });

        return back()->with(
            'success',
            sprintf('%d entries approved · £%s', $count, number_format($total, 2)),
        );
    }

    public function markPaid(int $id, Request $request): RedirectResponse
    {
        $entry = CommissionLedger::findOrFail($id);

        if ($entry->status !== 'approved') {
            return back()->with('error', 'Only approved entries can be marked paid.');
        }

        DB::transaction(function () use ($entry, $request) {
            $before = ['status' => $entry->status];
            $entry->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $this->logActivity(
                $request,
                'commission.paid',
                'commission_ledger',
                $entry->id,
                $before,
                ['status' => 'paid', 'amount' => $entry->commission_amount],
            );
        });

        return back()->with('success', 'Commission entry marked as paid.');
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Helpers
     * ─────────────────────────────────────────────────────────────────── */

    /**
     * Human-readable summary of a CommissionRule based on its type+config.
     * Kept here (not on the model) so it can stay UI-shaped without
     * leaking presentation into the domain layer.
     */
    private function describeRule(CommissionRule $rule): string
    {
        $config = $rule->config ?? [];

        return match ($rule->type) {
            'hybrid' => sprintf(
                'Hybrid · £%s onboarding + %s%% MRR',
                number_format((float) ($config['onboarding'] ?? 0), 0),
                number_format((float) ($config['mrr_percent'] ?? 0), 0),
            ),
            'tiered' => isset($config['tiers']) && is_array($config['tiers'])
                ? 'Tiered · £'.min(array_column($config['tiers'], 'amount')).'–£'.max(array_column($config['tiers'], 'amount')).'/mo'
                : 'Tiered',
            'flat' => sprintf('Flat · £%s/mo', number_format((float) ($config['amount'] ?? 0), 0)),
            'percent' => sprintf('Percent · %s%% MRR', number_format((float) ($config['percent'] ?? 0), 0)),
            'onboarding' => sprintf('Onboarding · £%s one-off', number_format((float) ($config['amount'] ?? 0), 0)),
            default => Str::headline($rule->type),
        };
    }

    private function describeLedgerEntry(CommissionLedger $entry): string
    {
        $product = $entry->product;
        $productName = $product ? $product->name : 'Commission';
        $customer = $entry->customer;

        $month = '';
        if ($entry->period_start) {
            $month = $entry->period_start->format('M Y');
        } elseif ($entry->created_at) {
            $month = $entry->created_at->format('M Y');
        }

        return match ($entry->trigger_type) {
            'onboarding' => $customer
                ? sprintf('%s onboarding · %s', $productName, $customer->name)
                : sprintf('%s onboarding', $productName),
            'invoice_paid' => sprintf('%s · invoice paid', $productName),
            default => sprintf('%s recurring · %s', $productName, $month),
        };
    }

    private function logActivity(
        Request $request,
        string $action,
        string $entityType,
        int $entityId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
