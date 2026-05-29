<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ProductOverviewController extends Controller
{
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

        return Inertia::render('Internal/Products/Show', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'icon_colour' => $product->icon_colour,
                'description' => $product->description,
                'is_active' => $product->is_active,
            ],
            'kpis' => $this->buildKpis($product, $monthStart, $sevenDaysOut),
            'plan_distribution' => $this->buildPlanDistribution($product),
            'no_plan_count' => CustomerProduct::where('product_id', $product->id)
                ->whereNull('plan_id')
                ->whereIn('status', ['active', 'trial'])
                ->count(),
            'recent_customers' => $this->buildRecentCustomers($product),
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
