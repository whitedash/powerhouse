<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\CustomerReferral;
use App\Models\Referrer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
     * SHOW — staff-facing detail page for a single referrer.
     *
     * Surfaces the per-referrer numbers that get rolled up on the
     * /referrers index: KPI cards, paginated commission ledger,
     * the customers they brought in, their active commission rules,
     * and a 6-month trend for performance review.
     * ─────────────────────────────────────────────────────────────── */

    public function show(int $id): Response
    {
        $referrer = Referrer::with('user:id,name,email,role,created_at,avatar_colour')
            ->findOrFail($id);

        if (! $referrer->user) {
            abort(404, 'Referrer is missing its user record.');
        }

        $now = now();
        $monthStart = $now->copy()->startOfMonth();

        // ── KPI bucket. Each line stands alone because the dashboard
        //    card it feeds also reads it that way; pre-aggregating
        //    here keeps the Vue side dumb.
        $kpis = [
            'total_customers' => CustomerReferral::where('referrer_id', $id)->count(),
            'active_customers' => CustomerReferral::where('referrer_id', $id)
                ->whereHas('customer', fn ($q) => $q->whereNull('archived_at'))
                ->count(),
            'pending_commission' => (float) CommissionLedger::where('referrer_id', $id)
                ->where('status', 'pending')
                ->sum('commission_amount'),
            'approved_commission' => (float) CommissionLedger::where('referrer_id', $id)
                ->where('status', 'approved')
                ->sum('commission_amount'),
            'paid_all_time' => (float) CommissionLedger::where('referrer_id', $id)
                ->where('status', 'paid')
                ->sum('commission_amount'),
            'paid_this_year' => (float) CommissionLedger::where('referrer_id', $id)
                ->where('status', 'paid')
                ->whereYear('paid_at', $now->year)
                ->sum('commission_amount'),
            'this_month' => (float) CommissionLedger::where('referrer_id', $id)
                ->where('created_at', '>=', $monthStart)
                ->sum('commission_amount'),
        ];

        // ── Paginated ledger. Reuses describeLedgerEntry() so the
        //    detail page reads identical labels to the global ledger
        //    on /referrers.
        $ledger = CommissionLedger::where('referrer_id', $id)
            ->with([
                'customer:id,name',
                'product:id,name,slug,icon_colour',
            ])
            ->orderByDesc('created_at')
            ->paginate(15)
            ->through(fn (CommissionLedger $c): array => [
                'id' => $c->id,
                'customer_name' => $c->customer?->name,
                'product_name' => $c->product?->name,
                'product_slug' => $c->product?->slug,
                'product_colour' => $c->product?->icon_colour,
                'trigger_type' => $c->trigger_type,
                'description' => $this->describeLedgerEntry($c),
                'gross_amount' => (float) $c->gross_amount,
                'commission_amount' => (float) $c->commission_amount,
                'status' => $c->status,
                'period_start' => $c->period_start?->format('d M Y'),
                'period_end' => $c->period_end?->format('d M Y'),
                'approved_at' => $c->approved_at?->format('d M Y'),
                'paid_at' => $c->paid_at?->format('d M Y'),
                'created_at' => $c->created_at?->format('d M Y'),
            ]);

        // ── Customers attributed to this referrer + their current
        //    active/trial products. Eager-load both the customer
        //    and its customerProducts → product chain so the row
        //    can paint a product chip strip without N+1s.
        $customers = CustomerReferral::where('referrer_id', $id)
            ->with([
                'customer:id,name,city,created_at,archived_at',
                'customer.customerProducts' => fn ($q) => $q
                    ->whereIn('status', ['active', 'trial'])
                    ->with('product:id,name,slug,icon_colour'),
            ])
            ->orderByDesc('attributed_at')
            ->get()
            ->map(function (CustomerReferral $r) use ($id): array {
                $cust = $r->customer;

                return [
                    'customer_id' => $r->customer_id,
                    'customer_name' => $cust?->name,
                    'customer_city' => $cust?->city,
                    'is_archived' => $cust?->archived_at !== null,
                    'attributed_at' => $r->attributed_at?->format('d M Y'),
                    'products' => $cust
                        ? $cust->customerProducts->map(fn ($cp): array => [
                            'name' => $cp->product?->name,
                            'slug' => $cp->product?->slug,
                            'colour' => $cp->product?->icon_colour,
                            'status' => $cp->status,
                        ])->values()->all()
                        : [],
                    'total_commission' => (float) CommissionLedger::where('referrer_id', $id)
                        ->where('customer_id', $r->customer_id)
                        ->sum('commission_amount'),
                ];
            })
            ->values()
            ->all();

        // ── Active commission rules. The existing describeRule()
        //    already produces the UI string for the index page,
        //    so the show page renders the same vocabulary.
        $rules = CommissionRule::where('referrer_id', $id)
            ->where('is_active', true)
            ->with('product:id,name,slug')
            ->get()
            ->map(fn (CommissionRule $r): array => [
                'id' => $r->id,
                'product_name' => $r->product?->name,
                'product_slug' => $r->product?->slug,
                'type' => $r->type,
                'config' => $r->config,
                'valid_from' => $r->valid_from?->format('d M Y'),
                'valid_until' => $r->valid_until?->format('d M Y'),
                'description' => $this->describeRule($r),
            ])
            ->values()
            ->all();

        // ── Six-month trend. range(5, 0) walks backwards from
        //    5 months ago → this month so the bars render
        //    left-to-right oldest→newest.
        $trend = collect(range(5, 0))
            ->map(function (int $monthsAgo) use ($id, $now): array {
                $date = $now->copy()->subMonthsNoOverflow($monthsAgo);

                return [
                    'month' => $date->format('M'),
                    'new_customers' => CustomerReferral::where('referrer_id', $id)
                        ->whereYear('attributed_at', $date->year)
                        ->whereMonth('attributed_at', $date->month)
                        ->count(),
                    'commission' => (float) CommissionLedger::where('referrer_id', $id)
                        ->whereYear('created_at', $date->year)
                        ->whereMonth('created_at', $date->month)
                        ->sum('commission_amount'),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Internal/Referrers/Show', [
            'referrer' => [
                'id' => $referrer->id,
                'name' => $referrer->user->name,
                'email' => $referrer->user->email,
                'avatar_colour' => $referrer->user->avatar_colour,
                'is_active' => $referrer->is_active,
                'member_since' => $referrer->user->created_at?->format('d M Y'),
                // last_login is a future column — exposed as null so the
                // template binding doesn't break when the field lands.
                'last_login' => null,
            ],
            'kpis' => $kpis,
            'ledger' => $ledger,
            'customers' => $customers,
            'rules' => $rules,
            'trend' => $trend,
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
            'temp_password_name' => $data['name'],
            'temp_password_email' => $data['email'],
        ]);
    }

    /**
     * Edit a referrer. Touches the linked User (name + email) and the
     * Referrer row (is_active). commission_note is currently a
     * write-only field — captured in the audit log so we know the
     * rationale a super_admin recorded at the time, but not stored
     * on the model itself (no column for it yet).
     */
    public function update(int $id, Request $request): RedirectResponse
    {
        $referrer = Referrer::with('user')->findOrFail($id);
        if (! $referrer->user) {
            return back()->withErrors(['referrer' => 'This referrer has no linked user account.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($referrer->user->id)],
            'commission_note' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = [
            'name' => $referrer->user->name,
            'email' => $referrer->user->email,
            'is_active' => $referrer->is_active,
        ];

        DB::transaction(function () use ($referrer, $data, $request, $before) {
            $referrer->user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
            ])->save();

            $referrer->is_active = $request->boolean('is_active', $referrer->is_active);
            $referrer->save();

            $this->logActivity($request, 'referrer.updated', 'referrer', $referrer->id, $before, [
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $referrer->is_active,
                'commission_note' => $data['commission_note'] ?? null,
            ]);
        });

        return back()->with('success', "Updated {$data['name']}.");
    }

    /**
     * Rotate a referrer's password. Returns the plaintext via flash
     * exactly once so staff can relay it. Never stored or logged in
     * plaintext anywhere.
     */
    public function resetPassword(int $id, Request $request): RedirectResponse
    {
        $referrer = Referrer::with('user')->findOrFail($id);
        if (! $referrer->user) {
            return back()->withErrors(['referrer' => 'This referrer has no linked user account.']);
        }

        $tempPassword = Str::random(16);

        $referrer->user->forceFill([
            'password' => Hash::make($tempPassword),
        ])->save();

        $this->logActivity($request, 'referrer.password_reset', 'referrer', $referrer->id, after: [
            'reset_by_user_id' => $request->user()?->id,
        ]);

        return back()->with([
            'success' => "Password reset for {$referrer->user->name}.",
            'temp_password' => $tempPassword,
            'temp_password_name' => $referrer->user->name,
            'temp_password_email' => $referrer->user->email,
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

            // Mirror the payout into the expenses ledger so the
            // books reconcile without a manual step. The helper is
            // idempotent — safe against a re-submit of the same
            // entry from a stuck UI.
            $entry->loadMissing(['referrer.user:id,name', 'customer:id,name']);
            ExpenseController::createFromCommission(
                $entry,
                $request->user()->id,
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
