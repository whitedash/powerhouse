<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\Referrer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            return [
                'headline' => $this->buildHeadline(),
                'mrr_trend' => $this->buildMrrTrend(),
                'by_product' => $this->buildByProduct(),
                'customer_growth' => $this->buildCustomerGrowth(),
                'top_referrers' => $this->buildTopReferrers(),
                'plan_popularity' => $this->buildPlanPopularity(),
                'range' => $range,
            ];
        });

        return Inertia::render('Internal/Analytics/Index', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHeadline(): array
    {
        $activeSubs = CustomerProduct::where('status', 'active')->get();

        return [
            'total_mrr' => round($activeSubs->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution), 2),
            'total_arr' => round($activeSubs->sum(fn (CustomerProduct $cp): float => $cp->arr_contribution), 2),
            'total_customers' => Customer::whereNull('archived_at')->count(),
            'paying_customers' => CustomerProduct::where('status', 'active')
                ->distinct()
                ->count('customer_id'),
            'trial_customers' => CustomerProduct::where('status', 'trial')
                ->distinct()
                ->count('customer_id'),
            'churn_rate' => $this->calcChurnRate(),
            'avg_revenue_per_customer' => $this->calcArpc(),
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
    private function buildByProduct(): array
    {
        return Product::where('is_active', true)
            ->get()
            ->map(function (Product $p): array {
                $active = CustomerProduct::where('product_id', $p->id)
                    ->where('status', 'active')
                    ->get();

                return [
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'icon_colour' => $p->icon_colour,
                    'mrr' => round(
                        $active->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution),
                        2,
                    ),
                    'active' => $active->count(),
                    'trial' => CustomerProduct::where('product_id', $p->id)
                        ->where('status', 'trial')
                        ->count(),
                    'churned_this_month' => CustomerProduct::where('product_id', $p->id)
                        ->where('status', 'cancelled')
                        ->where('cancelled_at', '>=', now()->startOfMonth())
                        ->count(),
                ];
            })
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
                    'new' => Customer::whereYear('created_at', $date->year)
                        ->whereMonth('created_at', $date->month)
                        ->count(),
                    'archived' => Customer::whereNotNull('archived_at')
                        ->whereYear('archived_at', $date->year)
                        ->whereMonth('archived_at', $date->month)
                        ->count(),
                    'cumulative' => Customer::whereNull('archived_at')
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
    private function buildPlanPopularity(): array
    {
        return ProductPlan::query()
            ->withCount(['customerProducts as active_count' => fn ($q) => $q->where('status', 'active')])
            ->with('product:id,name,icon_colour')
            ->having('active_count', '>', 0)
            ->orderByDesc('active_count')
            ->take(10)
            ->get()
            ->map(fn (ProductPlan $p): array => [
                'plan_name' => $p->name,
                'product_name' => $p->product?->name,
                'icon_colour' => $p->product?->icon_colour,
                'active_count' => $p->active_count,
                'mrr' => round(
                    CustomerProduct::where('plan_id', $p->id)
                        ->where('status', 'active')
                        ->get()
                        ->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution),
                    2,
                ),
            ])
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
     * Average MRR per paying customer (active subs only).
     */
    private function calcArpc(): float
    {
        $paying = CustomerProduct::where('status', 'active')
            ->distinct()
            ->count('customer_id');

        if ($paying === 0) {
            return 0.0;
        }

        $mrr = CustomerProduct::where('status', 'active')
            ->get()
            ->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution);

        return round($mrr / $paying, 2);
    }
}
