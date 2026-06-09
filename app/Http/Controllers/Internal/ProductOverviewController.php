<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use App\Models\Domain;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\Website;
use App\Support\RecurringRevenue;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ProductOverviewController extends Controller
{
    /**
     * Whitedash's book isn't just its service CPs — its websites carry hosting
     * and its customers hold domains. Those asset sources feed this product's
     * overview KPIs (active customers + MRR + recent customers). Every other
     * product is service-only and keeps the CP-based figures untouched.
     */
    private const WHITEDASH_SLUG = 'whitedash';

    /**
     * One overview page per product. Aggregates KPIs, plan distribution,
     * recent customers, recent product-level activity, and a 6-month
     * trend so staff can land on a product and see the shape of its
     * book without bouncing between Customers / Subscriptions /
     * Provisioning.
     */
    public function show(string $slug): Response
    {
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'planCategories.plans.activePrices',
                'plans' => fn ($q) => $q
                    ->whereNull('category_id')
                    ->with('activePrices'),
            ])
            ->firstOrFail();

        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $sevenDaysOut = $now->copy()->addDays(7);

        $kpis = $this->buildKpis($product, $monthStart, $sevenDaysOut);
        $recentCustomers = $this->buildRecentCustomers($product);

        // Whitedash only: fold hosting + domains into the customer-facing KPIs,
        // drawn from the canonical RecurringRevenue aggregator so this overview
        // agrees with the customer list / dashboard. Trial / churn / trend stay
        // subscription-only (assets have no lifecycle history to backfill).
        if ($product->slug === self::WHITEDASH_SLUG) {
            $rr = RecurringRevenue::compute();

            // Distinct customers: this product's service CPs ∪ all active
            // hosting ∪ all plan-matched auto_renew domains.
            $activeIds = array_unique(array_merge(
                $rr->serviceCustomerIds($product->id),
                $rr->hostingCustomerIds(),
                $rr->domainCustomerIds(),
            ));

            // MRR = services(this product) + hosting + domains. Uses bySource so
            // it reconciles exactly with the aggregator regardless of which
            // catalog product the hosting/domain plans live under.
            $mrr = round($rr->serviceMonthly($product->id) + $rr->bySource['hosting'] + $rr->bySource['domains'], 2);

            $kpis['active_customers'] = count($activeIds);
            $kpis['mrr'] = $mrr;
            $kpis['arr'] = round($mrr * 12, 2);

            $recentCustomers = $this->buildWhitedashRecentCustomers($product);
        }

        return Inertia::render('Internal/Products/Show', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'icon_colour' => $product->icon_colour,
                'description' => $product->description,
                'is_active' => $product->is_active,
            ],
            'kpis' => $kpis,
            'plan_distribution' => $this->buildPlanDistribution($product),
            'no_plan_count' => CustomerProduct::where('product_id', $product->id)
                ->whereNull('plan_id')
                ->whereIn('status', ['active', 'trial'])
                ->count(),
            'recent_customers' => $recentCustomers,
            'activity' => $this->buildActivity($product),
            'trend' => $this->buildTrend($product),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildKpis(Product $product, Carbon $monthStart, Carbon $sevenDaysOut): array
    {
        // mrr_contribution / arr_contribution are model accessors so
        // they have to be summed in PHP, not SQL — mirrors the
        // SubscriptionController / DashboardController pattern.
        $activeSubs = CustomerProduct::where('product_id', $product->id)
            ->where('status', 'active')
            ->with('planPrice')
            ->get();

        return [
            'active_customers' => $activeSubs->count(),
            'mrr' => round($activeSubs->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution), 2),
            'arr' => round($activeSubs->sum(fn (CustomerProduct $cp): float => $cp->arr_contribution), 2),
            'trial_count' => CustomerProduct::where('product_id', $product->id)
                ->where('status', 'trial')
                ->count(),
            'trial_converting_soon' => CustomerProduct::where('product_id', $product->id)
                ->where('status', 'trial')
                ->whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '<=', $sevenDaysOut)
                ->count(),
            'new_this_month' => CustomerProduct::where('product_id', $product->id)
                ->whereIn('status', ['active', 'trial'])
                ->where('started_at', '>=', $monthStart)
                ->count(),
            'churned_this_month' => CustomerProduct::where('product_id', $product->id)
                ->where('status', 'cancelled')
                ->where('cancelled_at', '>=', $monthStart)
                ->count(),
            'suspended_count' => CustomerProduct::where('product_id', $product->id)
                ->where('status', 'suspended')
                ->count(),
        ];
    }

    /**
     * Plans that have at least one active or trial subscription,
     * with per-plan counts + MRR + a compact price summary.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildPlanDistribution(Product $product): array
    {
        return ProductPlan::where('product_id', $product->id)
            ->with(['activePrices', 'category'])
            ->orderBy('sort_order')
            ->get()
            ->map(function (ProductPlan $plan): array {
                $activeForPlan = CustomerProduct::where('plan_id', $plan->id)
                    ->where('status', 'active')
                    ->with('planPrice')
                    ->get();

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'category_name' => $plan->category?->name,
                    'active_customers' => $activeForPlan->count(),
                    'trial_customers' => CustomerProduct::where('plan_id', $plan->id)
                        ->where('status', 'trial')
                        ->count(),
                    'mrr' => round(
                        $activeForPlan->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution),
                        2,
                    ),
                    'prices_summary' => $plan->activePrices
                        ->map(fn (ProductPlanPrice $pp): array => [
                            'interval_label' => $pp->interval_label,
                            'price' => (float) $pp->price,
                            'is_default' => $pp->is_default,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $p): bool => $p['active_customers'] > 0 || $p['trial_customers'] > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentCustomers(Product $product): array
    {
        return CustomerProduct::where('product_id', $product->id)
            ->whereIn('status', ['active', 'trial'])
            ->with([
                'customer:id,name,city,pipeline_stage',
                'productPlan:id,name',
                'planPrice:id,price,interval_count,interval_unit',
            ])
            ->orderByDesc('started_at')
            ->take(8)
            ->get()
            ->map(fn (CustomerProduct $cp): array => [
                'customer_id' => $cp->customer_id,
                'customer_name' => $cp->customer?->name,
                'customer_city' => $cp->customer?->city,
                'plan_name' => $cp->productPlan ? $cp->productPlan->name : $cp->plan,
                'interval_label' => $cp->planPrice ? $cp->planPrice->interval_label : $cp->interval_label,
                'price' => $cp->planPrice
                    ? (float) $cp->planPrice->price
                    : (float) ($cp->price_monthly ?? 0),
                'status' => $cp->status,
                'started_at' => $cp->started_at?->toIso8601String(),
                'trial_ends_at' => $cp->trial_ends_at?->toIso8601String(),
                'mrr' => $cp->mrr_contribution,
            ])
            ->all();
    }

    /**
     * Whitedash recent customers — its service CPs PLUS asset customers
     * (active hosting websites with a plan + plan-matched auto_renew domains),
     * deduped to DISTINCT customers (most-recent arrangement wins), newest
     * first, capped at 8. Mirrors the service-CP row shape so the Vue renders
     * them identically.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildWhitedashRecentCustomers(Product $product): array
    {
        $services = collect($this->buildRecentCustomers($product));

        $hosting = Website::where('hosting_status', 'active')
            ->whereNotNull('plan_price_id')
            ->with(['customer:id,name,city', 'plan:id,name', 'planPrice:id,price,interval_count,interval_unit'])
            ->orderByDesc('created_at')
            ->take(8)
            ->get()
            ->map(fn (Website $w): array => [
                'customer_id' => $w->customer_id,
                'customer_name' => $w->customer?->name,
                'customer_city' => $w->customer?->city,
                'plan_name' => $w->plan?->name ? $w->plan->name.' hosting' : 'Hosting',
                'interval_label' => $w->planPrice->interval_label,
                'price' => (float) $w->planPrice->price,
                'status' => 'active',
                'started_at' => $w->created_at?->toIso8601String(),
                'trial_ends_at' => null,
                'mrr' => (float) $w->planPrice->mrr_contribution,
            ]);

        $domains = Domain::where('auto_renew', true)
            ->whereNotNull('product_plan_id')
            ->with(['customer:id,name,city', 'plan:id,name', 'plan.activePrices'])
            ->orderByDesc('created_at')
            ->take(8)
            ->get()
            ->map(function (Domain $d): ?array {
                $tier = $d->plan?->activePrices->first();
                if ($d->plan === null || $tier === null) {
                    return null;
                }

                return [
                    'customer_id' => $d->customer_id,
                    'customer_name' => $d->customer?->name,
                    'customer_city' => $d->customer?->city,
                    'plan_name' => 'Domain · '.$d->plan->name,
                    'interval_label' => $tier->interval_label,
                    'price' => (float) $tier->price,
                    'status' => 'active',
                    'started_at' => $d->created_at?->toIso8601String(),
                    'trial_ends_at' => null,
                    'mrr' => (float) $tier->mrr_contribution,
                ];
            })
            ->filter();

        return $services->concat($hosting)->concat($domains)
            ->filter(fn (array $r): bool => $r['customer_id'] !== null)
            ->sortByDesc('started_at')
            ->unique('customer_id')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * product.enabled + product.suspended audit-log entries that
     * mention this product. The product_id is stashed in the JSON
     * after-payload by CustomerController + ProvisioningController,
     * so a JSON-path where() finds them with one round-trip.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildActivity(Product $product): array
    {
        return ActivityLog::where('entity_type', 'customer')
            ->whereIn('action', ['product.enabled', 'product.suspended'])
            ->where('after->product_id', $product->id)
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(fn (ActivityLog $a): array => [
                'id' => $a->id,
                'action' => $a->action,
                'after' => $a->after,
                'customer_id' => $a->entity_id,
                'created_at' => $a->created_at?->toIso8601String(),
                'time_ago' => $a->created_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Last 6 months of new + churned counts. Uses started_at /
     * cancelled_at as the truth source so it captures every
     * lifecycle event regardless of current status.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTrend(Product $product): array
    {
        return collect(range(5, 0))
            ->map(function (int $monthsAgo) use ($product): array {
                $date = now()->subMonths($monthsAgo);

                return [
                    'month' => $date->format('M'),
                    'year' => $date->format('Y'),
                    'new' => CustomerProduct::where('product_id', $product->id)
                        ->whereIn('status', ['active', 'trial', 'suspended', 'cancelled'])
                        ->whereYear('started_at', $date->year)
                        ->whereMonth('started_at', $date->month)
                        ->count(),
                    'churned' => CustomerProduct::where('product_id', $product->id)
                        ->where('status', 'cancelled')
                        ->whereYear('cancelled_at', $date->year)
                        ->whereMonth('cancelled_at', $date->month)
                        ->count(),
                ];
            })
            ->all();
    }
}
