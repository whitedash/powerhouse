<?php

namespace App\Http\Controllers\Internal;

use App\Enums\SlaState;
use App\Http\Controllers\Controller;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\Referrer;
use App\Models\SupportTicket;
use App\Support\RecurringRevenue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    /**
     * Platform-wide analytics. The headline cards are point-in-time;
     * the trend / growth sections walk the last 12 months for
     * recharts-style series. Everything that touches an accessor
     * (mrr_contribution / arr_contribution) reduces in PHP because
     * those aren't columns.
     */
    public function index(Request $request): Response
    {
        // Clamp the range so a bad ?range= can't blow out the trend
        // window. The cache key uses the clamped value too.
        $range = min(365, max(30, (int) $request->query('range', 90)));

        $payload = Cache::remember("analytics.index.{$range}", 300, function () use ($range): array {
            // One canonical pass over all three recurring-revenue sources,
            // shared by the headline, per-product and per-plan sections.
            $rr = RecurringRevenue::compute();

            return [
                'headline' => $this->buildHeadline($rr),
                'mrr_trend' => $this->buildMrrTrend(),
                // The historical trend + churn are SUBSCRIPTION-ONLY: hosting/
                // domains have no historical status timeline, so including them
                // would fabricate past months. The headline above is whole.
                'mrr_trend_basis' => 'subscriptions',
                'by_product' => $this->buildByProduct($rr),
                'customer_growth' => $this->buildCustomerGrowth(),
                'top_referrers' => $this->buildTopReferrers(),
                'plan_popularity' => $this->buildPlanPopularity($rr),
                'support' => $this->buildSupport(),
                'range' => $range,
            ];
        });

        return Inertia::render('Internal/Analytics/Index', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHeadline(RecurringRevenue $rr): array
    {
        // Whole recurring revenue (services + hosting + domains) from the
        // canonical aggregator. Paying customers = anyone with a recurring
        // arrangement of ANY source, so ARPC stays consistent with the total.
        $payingCustomers = count($rr->perCustomer);

        return [
            'total_mrr' => $rr->total,
            'total_arr' => $rr->arr(),
            'total_customers' => Company::whereNull('archived_at')->count(),
            'paying_customers' => $payingCustomers,
            'trial_customers' => CustomerProduct::where('status', 'trial')
                ->distinct()
                ->count('customer_id'),
            'churn_rate' => $this->calcChurnRate(),
            'avg_revenue_per_customer' => $payingCustomers > 0 ? round($rr->total / $payingCustomers, 2) : 0.0,
        ];
    }

    /**
     * 12 months of historical MRR + new + churned. "Active at
     * month-end" = a row whose started_at <= month_end AND status is
     * active OR cancelled after month_end. The grouped where()
     * keeps the SQL precedence honest (both branches share the
     * started_at clamp).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMrrTrend(): array
    {
        return collect(range(11, 0))
            ->map(function (int $monthsAgo): array {
                $date = now()->subMonths($monthsAgo);
                $monthEnd = $date->copy()->endOfMonth();

                $activeAtMonthEnd = CustomerProduct::where('started_at', '<=', $monthEnd)
                    ->where(function ($q) use ($monthEnd) {
                        $q->where('status', 'active')
                            ->orWhere(function ($q2) use ($monthEnd) {
                                $q2->where('status', 'cancelled')
                                    ->where('cancelled_at', '>', $monthEnd);
                            });
                    })
                    ->with('planPrice')
                    ->get();

                return [
                    'month' => $date->format('M Y'),
                    'month_short' => $date->format('M'),
                    'mrr' => round(
                        $activeAtMonthEnd->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution),
                        2,
                    ),
                    'new_customers' => CustomerProduct::whereIn('status', ['active', 'trial', 'suspended', 'cancelled'])
                        ->whereYear('started_at', $date->year)
                        ->whereMonth('started_at', $date->month)
                        ->distinct()
                        ->count('customer_id'),
                    'churned' => CustomerProduct::where('status', 'cancelled')
                        ->whereYear('cancelled_at', $date->year)
                        ->whereMonth('cancelled_at', $date->month)
                        ->count(),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildByProduct(RecurringRevenue $rr): array
    {
        return Product::where('is_active', true)
            ->get()
            ->map(fn (Product $p): array => [
                'name' => $p->name,
                'slug' => $p->slug,
                'icon_colour' => $p->icon_colour,
                // Whole recurring revenue + active arrangements for this product
                // across all three sources (a Hosting/Domains product now earns
                // via websites / domains, not CustomerProducts).
                'mrr' => $rr->productMonthly($p->id),
                'active' => $rr->productCount($p->id),
                'trial' => CustomerProduct::where('product_id', $p->id)
                    ->where('status', 'trial')
                    ->count(),
                'churned_this_month' => CustomerProduct::where('product_id', $p->id)
                    ->where('status', 'cancelled')
                    ->where('cancelled_at', '>=', now()->startOfMonth())
                    ->count(),
            ])
            ->sortByDesc('mrr')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomerGrowth(): array
    {
        return collect(range(11, 0))
            ->map(function (int $monthsAgo): array {
                $date = now()->subMonths($monthsAgo);
                $monthEnd = $date->copy()->endOfMonth();

                return [
                    'month' => $date->format('M Y'),
                    'month_short' => $date->format('M'),
                    'new' => Company::whereYear('created_at', $date->year)
                        ->whereMonth('created_at', $date->month)
                        ->count(),
                    'archived' => Company::whereNotNull('archived_at')
                        ->whereYear('archived_at', $date->year)
                        ->whereMonth('archived_at', $date->month)
                        ->count(),
                    'cumulative' => Company::whereNull('archived_at')
                        ->where('created_at', '<=', $monthEnd)
                        ->count(),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTopReferrers(): array
    {
        return Referrer::with('user:id,name')
            ->withCount('referrals as customer_count')
            ->get()
            ->map(function (Referrer $r): array {
                $user = $r->user;

                return [
                    'name' => $user ? $user->name : 'Unknown',
                    'customer_count' => $r->customer_count,
                    'pending_commission' => (float) CommissionLedger::where('referrer_id', $r->id)
                        ->where('status', 'pending')
                        ->sum('commission_amount'),
                    'paid_commission' => (float) CommissionLedger::where('referrer_id', $r->id)
                        ->where('status', 'paid')
                        ->sum('commission_amount'),
                ];
            })
            ->sortByDesc('customer_count')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPlanPopularity(RecurringRevenue $rr): array
    {
        // Per-plan attribution across all three sources (active arrangements +
        // recurring revenue). Names hydrated for the plans that actually have
        // active arrangements.
        $planIds = array_keys($rr->perPlan);
        if ($planIds === []) {
            return [];
        }

        $plans = ProductPlan::with('product:id,name,icon_colour')
            ->whereIn('id', $planIds)
            ->get()
            ->keyBy('id');

        return collect($rr->perPlan)
            ->filter(fn (array $b): bool => $b['count'] > 0)
            ->map(function (array $b, int $planId) use ($plans): ?array {
                $plan = $plans->get($planId);
                if ($plan === null) {
                    return null;
                }

                return [
                    'plan_name' => $plan->name,
                    'product_name' => $plan->product?->name,
                    'icon_colour' => $plan->product?->icon_colour,
                    'active_count' => $b['count'],
                    'mrr' => $b['monthly'],
                ];
            })
            ->filter()
            ->sortByDesc('active_count')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Pre-cohort baseline: how many active subs existed BEFORE the
     * month started. Divide this-month's churn by that baseline.
     * Returns a 1dp percentage.
     */
    private function calcChurnRate(): float
    {
        $activeStart = CustomerProduct::where('status', 'active')
            ->where('started_at', '<', now()->startOfMonth())
            ->count();

        if ($activeStart === 0) {
            return 0.0;
        }

        $churned = CustomerProduct::where('status', 'cancelled')
            ->where('cancelled_at', '>=', now()->startOfMonth())
            ->count();

        return round(($churned / $activeStart) * 100, 1);
    }

    /**
     * Support SLA + volume metrics. First-response is the hard SLA; breach
     * is computed in SQL with the same rule as SupportTicket::slaState
     * (responded-late OR unresponded-past-deadline). Resolution time is RAW
     * (resolved_at − created_at, no pause). CSAT joins this panel in Sprint 2.
     *
     * @return array<string, mixed>
     */
    private function buildSupport(): array
    {
        $total = SupportTicket::count();

        // First-response SLA buckets — reduced via SupportTicket::slaState()
        // so analytics can never drift from the badge logic (and so the
        // "resolved/closed without a response" tickets settle at resolution,
        // not as a live countdown). Excludes still-due from "decided".
        $met = $breached = 0;
        SupportTicket::query()
            ->whereNotNull('sla_breach_at')
            ->get(['id', 'status', 'sla_breach_at', 'first_responded_at', 'resolved_at', 'closed_at'])
            ->each(function (SupportTicket $t) use (&$met, &$breached): void {
                match ($t->slaState()) {
                    SlaState::Met => $met++,
                    SlaState::Breached => $breached++,
                    default => null, // due → not yet decided
                };
            });
        $decided = $met + $breached;

        // FRT average EXCLUDES unresponded tickets (no response to measure).
        // avg() is null with no matching rows → surfaced as null so the UI
        // shows "—" rather than a misleading "0h". MySQL TIMESTAMPDIFF.
        $frtSeconds = SupportTicket::whereNotNull('first_responded_at')
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, created_at, first_responded_at)'));
        $resolutionSeconds = SupportTicket::whereNotNull('resolved_at')
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, created_at, resolved_at)'));

        $reopened = SupportTicket::where('reopen_count', '>', 0)->count();

        return [
            'total' => $total,
            'avg_first_response_hours' => $frtSeconds !== null ? round((float) $frtSeconds / 3600, 1) : null,
            'avg_resolution_hours' => $resolutionSeconds !== null ? round((float) $resolutionSeconds / 3600, 1) : null,
            'met' => $met,
            'breached' => $breached,
            'pct_within_sla' => $decided > 0 ? round($met / $decided * 100, 1) : null,
            'breach_rate' => $decided > 0 ? round($breached / $decided * 100, 1) : null,
            'reopen_rate' => $total > 0 ? round($reopened / $total * 100, 1) : 0.0,
            'by_status' => $this->countBy('status'),
            'by_priority' => $this->countBy('priority'),
            'by_product' => SupportTicket::query()
                ->leftJoin('products', 'support_tickets.product_id', '=', 'products.id')
                ->selectRaw("COALESCE(products.name, 'Unassigned') as label, COUNT(*) as total")
                ->groupBy('label')
                ->orderByDesc('total')
                ->pluck('total', 'label')
                ->all(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countBy(string $column): array
    {
        return SupportTicket::query()
            ->selectRaw("{$column} as label, COUNT(*) as total")
            ->groupBy($column)
            ->pluck('total', 'label')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }
}
