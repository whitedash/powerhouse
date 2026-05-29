<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanCategory;
use App\Models\ProductPlanPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProvisioningController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString() ?: null;
        $productSlug = $request->string('product_slug')->toString() ?: null;
        $status = $request->string('status')->toString() ?: null;

        $customers = Customer::query()
            ->whereNull('archived_at')
            ->with([
                'customerProducts.product:id,name,slug,icon_colour,is_active,is_coming_soon',
                'primaryContact:id,customer_id,email',
            ])
            ->when($search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($productSlug, fn ($q, $slug) => $q->whereHas('customerProducts', fn ($q2) => $q2
                ->whereIn('status', ['active', 'trial'])
                ->whereHas('product', fn ($q3) => $q3->where('slug', $slug))
            ))
            ->when($status === 'has_active', fn ($q) => $q->whereHas('customerProducts', fn ($q2) => $q2->where('status', 'active')))
            ->when($status === 'no_products', fn ($q) => $q->whereDoesntHave('customerProducts', fn ($q2) => $q2->whereIn('status', ['active', 'trial'])))
            ->when($status === 'trial', fn ($q) => $q->whereHas('customerProducts', fn ($q2) => $q2->where('status', 'trial')))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(function (Customer $c): array {
                $products = $c->customerProducts
                    ->map(fn (CustomerProduct $cp): array => [
                        'id' => $cp->id,
                        'product_id' => $cp->product_id,
                        'slug' => $cp->product?->slug,
                        'name' => $cp->product?->name,
                        'icon_colour' => $cp->product?->icon_colour,
                        'status' => $cp->status,
                        'plan' => $cp->plan,
                        'price_monthly' => (float) ($cp->price_monthly ?? 0),
                        'trial_ends_at' => $cp->trial_ends_at?->toIso8601String(),
                        'updated_at' => $cp->updated_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all();

                // The "last changed" cell shows the most recent
                // customer_products row touched — gives operators a
                // signal for stale customers.
                $lastChanged = $c->customerProducts
                    ->pluck('updated_at')
                    ->filter()
                    ->max();

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'city' => $c->city,
                    'country' => $c->country,
                    'pipeline_stage' => $c->pipeline_stage,
                    'primary_email' => $c->primaryContact?->email,
                    'products' => $products,
                    'last_changed_at' => $lastChanged?->toIso8601String(),
                ];
            });

        $products = Product::query()
            ->where('is_active', true)
            ->orWhere('is_coming_soon', true)
            ->with([
                'activePlans.activePrices',
                'activePlans.category',
                'planCategories.activePlans.activePrices',
            ])
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'icon_colour', 'is_active', 'is_coming_soon'])
            ->map(function (Product $p): array {
                // Shared mapper so flat + categorised + uncategorised
                // ship the same plan shape.
                $mapPlan = fn (ProductPlan $plan, ?string $categoryName): array => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'category_id' => $plan->category_id,
                    'category_name' => $categoryName,
                    'features' => $plan->features ?? [],
                    'prices' => $plan->activePrices->map(fn (ProductPlanPrice $pp): array => [
                        'id' => $pp->id,
                        'price' => (float) $pp->price,
                        'interval_count' => $pp->interval_count,
                        'interval_unit' => $pp->interval_unit,
                        'interval_label' => $pp->interval_label,
                        'display_label' => $pp->display_label,
                        'label' => $pp->label,
                        'is_default' => $pp->is_default,
                    ])->values()->all(),
                ];

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'icon_colour' => $p->icon_colour,
                    'is_active' => $p->is_active,
                    'is_coming_soon' => $p->is_coming_soon,
                    'active_count' => CustomerProduct::where('product_id', $p->id)
                        ->where('status', 'active')
                        ->count(),
                    'plans' => $p->activePlans
                        ->map(fn (ProductPlan $plan): array => $mapPlan($plan, $plan->category?->name))
                        ->values()
                        ->all(),
                    'plan_categories' => $p->planCategories
                        ->map(fn (ProductPlanCategory $cat): array => [
                            'id' => $cat->id,
                            'name' => $cat->name,
                            'plans' => $cat->activePlans
                                ->map(fn (ProductPlan $plan): array => $mapPlan($plan, $cat->name))
                                ->values()
                                ->all(),
                        ])
                        ->values()
                        ->all(),
                    'uncategorised_plans' => $p->activePlans
                        ->whereNull('category_id')
                        ->map(fn (ProductPlan $plan): array => $mapPlan($plan, null))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $summary = [
            'total_customers' => Customer::whereNull('archived_at')->count(),
            'products' => array_map(fn (array $p): array => [
                'slug' => $p['slug'],
                'name' => $p['name'],
                'count' => $p['active_count'],
                'is_coming_soon' => $p['is_coming_soon'],
            ], $products),
        ];

        // Lookups for the slide-over and the quick-enable panel.
        // available_customers_for_quick is the un-archived list so
        // the picker matches what the index renders.
        return Inertia::render('Internal/Provisioning/Index', [
            'customers' => $customers,
            'products' => $products,
            'summary' => $summary,
            'billing_entities' => BillingEntity::where('is_active', true)->get(['id', 'name']),
            'all_customers' => Customer::whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name', 'city'])
                ->map(fn (Customer $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'city' => $c->city,
                ])
                ->all(),
            'filters' => [
                'search' => $search,
                'product_slug' => $productSlug,
                'status' => $status,
            ],
        ]);
    }

    public function toggle(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'action' => ['required', 'in:enable,suspend'],
            'plan_id' => ['nullable', 'integer', 'exists:product_plans,id'],
            'plan_price_id' => ['nullable', 'integer', 'exists:product_plan_prices,id'],
            'price_monthly' => ['nullable', 'numeric', 'min:0'],
            'plan' => ['nullable', 'string', 'max:100'],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:365'],
            'interval_unit' => ['nullable', 'in:day,week,month,year,one_time'],
            'billing_entity_id' => ['nullable', 'integer', 'exists:billing_entities,id'],
            'status' => ['nullable', 'in:active,trial'],
            'trial_ends_at' => ['nullable', 'date', 'required_if:status,trial'],
        ]);

        $customer = Customer::findOrFail($data['customer_id']);
        Gate::authorize('update', $customer);

        $existing = CustomerProduct::where('customer_id', $data['customer_id'])
            ->where('product_id', $data['product_id'])
            ->latest('id')
            ->first();

        if ($data['action'] === 'enable') {
            if ($existing && in_array($existing->status, ['active', 'trial'], true)) {
                return back()->with('error', 'Product already active for this customer.');
            }

            // Coming-soon products shouldn't be provisionable — the
            // grid disables the toggle, but the API is the source of
            // truth.
            $product = Product::findOrFail($data['product_id']);
            if (! $product->is_active) {
                return back()->with('error', "{$product->name} is not currently provisionable.");
            }

            $status = $data['status'] ?? 'active';

            // plan_price_id is the canonical source for price + interval.
            // plan_id (or the price's plan_id) sets the plan FK. Free-text
            // values are a fallback for one-off custom arrangements.
            $planPrice = ! empty($data['plan_price_id']) ? ProductPlanPrice::find($data['plan_price_id']) : null;
            $plan = ! empty($data['plan_id'])
                ? ProductPlan::find($data['plan_id'])
                : ($planPrice ? ProductPlan::find($planPrice->plan_id) : null);

            $planName = $plan ? $plan->name : ($data['plan'] ?? null);
            $price = $planPrice ? (float) $planPrice->price : ($data['price_monthly'] ?? 0);
            $intervalCount = $planPrice ? $planPrice->interval_count : (int) ($data['interval_count'] ?? 1);
            $intervalUnit = $planPrice ? $planPrice->interval_unit : ($data['interval_unit'] ?? 'month');

            DB::transaction(function () use ($customer, $data, $status, $request, $plan, $planPrice, $planName, $price, $intervalCount, $intervalUnit) {
                CustomerProduct::create([
                    'customer_id' => $customer->id,
                    'product_id' => $data['product_id'],
                    'plan_id' => $plan?->id,
                    'plan_price_id' => $planPrice?->id,
                    'billing_entity_id' => $data['billing_entity_id'] ?? null,
                    'plan' => $planName,
                    'price_monthly' => $price,
                    'interval_count' => $intervalCount,
                    'interval_unit' => $intervalUnit,
                    'status' => $status,
                    'trial_ends_at' => $data['trial_ends_at'] ?? null,
                    'started_at' => now(),
                ]);

                $this->logActivity($request, 'product.enabled', $customer->id, after: [
                    'product_id' => $data['product_id'],
                    'plan_id' => $plan?->id,
                    'plan_price_id' => $planPrice?->id,
                    'status' => $status,
                    'price' => $price,
                    'interval_count' => $intervalCount,
                    'interval_unit' => $intervalUnit,
                    'source' => 'provisioning_grid',
                ]);
            });

            Cache::forget('dash.mrr');
            Cache::forget('dash.total_customers');

            return back()->with('success', 'Product enabled.');
        }

        // SUSPEND
        if (! $existing || ! in_array($existing->status, ['active', 'trial'], true)) {
            return back()->with('error', 'Product not active for this customer.');
        }

        DB::transaction(function () use ($existing, $customer, $request) {
            $before = ['status' => $existing->status];
            $existing->update([
                'status' => 'suspended',
                'cancelled_at' => now(),
            ]);

            $this->logActivity($request, 'product.suspended', $customer->id, $before, [
                'customer_product_id' => $existing->id,
                'status' => 'suspended',
                'source' => 'provisioning_grid',
            ]);
        });

        Cache::forget('dash.mrr');
        Cache::forget('dash.total_customers');

        return back()->with('success', 'Product suspended.');
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
