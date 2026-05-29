<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\ProductPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    private const STATUSES = ['active', 'trial', 'suspended'];

    private const INTERVAL_UNITS = ['day', 'week', 'month', 'year', 'one_time'];

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString() ?: null;
        $productSlug = $request->string('product_slug')->toString() ?: null;
        $statusFilter = $request->string('status')->toString() ?: null;
        $intervalFilter = $request->string('interval_unit')->toString() ?: null;

        $subscriptions = CustomerProduct::query()
            ->with([
                'customer:id,name,city',
                'product:id,name,slug,icon_colour',
                'productPlan:id,name,price,interval_count,interval_unit',
                'billingEntity:id,name',
            ])
            ->whereIn('status', self::STATUSES)
            ->when($search, fn ($q, $s) => $q->whereHas('customer', fn ($q2) => $q2->where('name', 'like', "%{$s}%")))
            ->when($productSlug, fn ($q, $slug) => $q->whereHas('product', fn ($q2) => $q2->where('slug', $slug)))
            ->when($statusFilter, fn ($q, $st) => $q->where('status', $st))
            ->when($intervalFilter, fn ($q, $iv) => $q->where('interval_unit', $iv))
            ->orderByDesc('started_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CustomerProduct $cp): array => [
                'id' => $cp->id,
                'customer' => [
                    'id' => $cp->customer_id,
                    'name' => $cp->customer?->name,
                    'city' => $cp->customer?->city,
                ],
                'product' => [
                    'slug' => $cp->product?->slug,
                    'name' => $cp->product?->name,
                    'icon_colour' => $cp->product?->icon_colour,
                ],
                'plan' => $cp->plan,
                'plan_id' => $cp->plan_id,
                'plan_name' => $cp->productPlan ? $cp->productPlan->name : $cp->plan,
                'price_monthly' => (float) ($cp->price_monthly ?? 0),
                'effective_price' => $cp->effective_price,
                'interval_count' => $cp->interval_count,
                'interval_unit' => $cp->interval_unit,
                'interval_label' => $cp->interval_label,
                'status' => $cp->status,
                'started_at' => $cp->started_at?->toIso8601String(),
                'trial_ends_at' => $cp->trial_ends_at?->toIso8601String(),
                'next_billing_date' => $cp->next_billing_date?->toDateString(),
                'cancels_at' => $cp->cancels_at?->toDateString(),
                'discount_pct' => $cp->discount_pct !== null ? (float) $cp->discount_pct : null,
                'discount_expires_at' => $cp->discount_expires_at?->toDateString(),
                'stripe_subscription_id' => $cp->stripe_subscription_id,
                'stripe_price_id' => $cp->stripe_price_id,
                'billing_entity' => $cp->billingEntity
                    ? ['id' => $cp->billingEntity->id, 'name' => $cp->billingEntity->name]
                    : null,
                'mrr_contribution' => $cp->mrr_contribution,
            ]);

        // Plans grouped by product_id so the subscription edit
        // slide-over can render a plan picker without an extra
        // round-trip per row.
        $productPlans = ProductPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'product_id', 'name', 'price', 'interval_count', 'interval_unit'])
            ->groupBy('product_id')
            ->map(fn ($plans) => $plans->map(fn (ProductPlan $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
                'interval_count' => $p->interval_count,
                'interval_unit' => $p->interval_unit,
                'interval_label' => $p->interval_label,
            ])->values()->all());

        return Inertia::render('Internal/Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'analytics' => $this->buildAnalytics(),
            'products' => Product::where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug', 'icon_colour'])
                ->all(),
            'product_plans' => $productPlans,
            'billing_entities' => BillingEntity::where('is_active', true)
                ->get(['id', 'name'])
                ->all(),
            'statuses' => self::STATUSES,
            'interval_units' => self::INTERVAL_UNITS,
            'filters' => [
                'search' => $search,
                'product_slug' => $productSlug,
                'status' => $statusFilter,
                'interval_unit' => $intervalFilter,
            ],
        ]);
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $cp = CustomerProduct::with('customer')->findOrFail($id);
        Gate::authorize('update', $cp->customer);

        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:product_plans,id'],
            'plan' => ['nullable', 'string', 'max:100'],
            'price_monthly' => ['nullable', 'numeric', 'min:0'],
            'interval_count' => ['required', 'integer', 'min:1', 'max:365'],
            'interval_unit' => ['required', 'in:day,week,month,year,one_time'],
            'billing_entity_id' => ['nullable', 'integer', 'exists:billing_entities,id'],
            'next_billing_date' => ['nullable', 'date'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_expires_at' => ['nullable', 'date'],
            'stripe_subscription_id' => ['nullable', 'string', 'max:100'],
            'stripe_price_id' => ['nullable', 'string', 'max:100'],
            'cancels_at' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($cp, $data, $request) {
            $before = [
                'plan' => $cp->plan,
                'price_monthly' => $cp->price_monthly,
                'interval_count' => $cp->interval_count,
                'interval_unit' => $cp->interval_unit,
            ];

            $cp->update($data);

            $this->logActivity($request, 'subscription.updated', $cp->customer_id, $before, [
                'subscription_id' => $cp->id,
                'plan' => $cp->plan,
                'price' => $cp->price_monthly,
                'interval' => $cp->interval_label,
            ]);
        });

        // Pricing/interval moves change the dashboard MRR figure;
        // bust the cache so the next dashboard render doesn't lie.
        Cache::forget('dash.mrr');

        return back()->with('success', 'Subscription updated.');
    }

    public function cancel(int $id, Request $request): RedirectResponse
    {
        $cp = CustomerProduct::with('customer')->findOrFail($id);
        Gate::authorize('update', $cp->customer);

        $data = $request->validate([
            'immediately' => ['nullable', 'boolean'],
            'cancels_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $immediately = (bool) ($data['immediately'] ?? false);

        if (! $immediately && empty($data['cancels_at'])) {
            return back()->with('error', 'Pick a cancellation date or check "Cancel immediately".');
        }

        if (! in_array($cp->status, ['active', 'trial'], true)) {
            return back()->with('error', 'Only active or trial subscriptions can be cancelled.');
        }

        DB::transaction(function () use ($cp, $immediately, $data, $request) {
            $before = ['status' => $cp->status, 'cancels_at' => $cp->cancels_at?->toDateString()];

            if ($immediately) {
                $cp->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancels_at' => null,
                ]);
            } else {
                $cp->update([
                    'cancels_at' => $data['cancels_at'],
                ]);
            }

            $this->logActivity($request, 'subscription.cancelled', $cp->customer_id, $before, [
                'subscription_id' => $cp->id,
                'immediately' => $immediately,
                'cancels_at' => $cp->cancels_at?->toDateString(),
            ]);
        });

        Cache::forget('dash.mrr');
        Cache::forget('dash.total_customers');

        return back()->with(
            'success',
            $immediately ? 'Subscription cancelled.' : 'Cancellation scheduled.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAnalytics(): array
    {
        // MRR is computed in PHP rather than SQL because the math
        // (discount + interval) lives on the model. The 'active' set
        // is small enough that the round-trip is fine; if it ever
        // gets large, we can collapse this into a derived column.
        $activeSubs = CustomerProduct::where('status', 'active')->get();
        $mrr = round($activeSubs->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution), 2);
        $arr = round($mrr * 12, 2);

        $trialCount = CustomerProduct::where('status', 'trial')->count();
        $trialConvertingSoon = CustomerProduct::where('status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now()->addDays(7))
            ->count();

        $churnedThisMonth = CustomerProduct::where('status', 'cancelled')
            ->where('cancelled_at', '>=', now()->startOfMonth())
            ->count();

        $newThisMonth = CustomerProduct::whereIn('status', ['active', 'trial'])
            ->where('started_at', '>=', now()->startOfMonth())
            ->count();

        $byProduct = Product::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (Product $p) use ($activeSubs): array {
                $forProduct = $activeSubs->where('product_id', $p->id);

                return [
                    'slug' => $p->slug,
                    'name' => $p->name,
                    'icon_colour' => $p->icon_colour,
                    'active_count' => $forProduct->count(),
                    'mrr' => round($forProduct->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution), 2),
                ];
            })
            ->values()
            ->all();

        return [
            'mrr' => $mrr,
            'arr' => $arr,
            'active_count' => $activeSubs->count(),
            'trial_count' => $trialCount,
            'trial_converting_soon' => $trialConvertingSoon,
            'churned_this_month' => $churnedThisMonth,
            'new_this_month' => $newThisMonth,
            'by_product' => $byProduct,
        ];
    }

    private function logActivity(
        Request $request,
        string $action,
        int $customerId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'customer',
            'entity_id' => $customerId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
