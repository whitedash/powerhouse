<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use Illuminate\Http\JsonResponse;

class PricingController extends Controller
{
    /**
     * Public pricing endpoint consumed by product marketing sites.
     *
     * Plans are grouped by ProductPlanCategory; uncategorised plans
     * live under a top-level "plans" array. Only is_active + is_public
     * rows surface so an operator can stage a category / plan / price
     * internally before exposing it.
     *
     * CORS is handled by config/cors.php (paths includes api/*; only
     * APP_URL / PORTAL_URL / REFERRER_URL origins are allowed).
     */
    public function __invoke(string $slug): JsonResponse
    {
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'planCategories' => fn ($q) => $q
                    ->where('is_public', true)
                    ->orderBy('sort_order')
                    ->with(['activePlans' => fn ($q2) => $q2
                        ->where('is_public', true)
                        ->orderBy('sort_order')
                        ->with('activePrices')]),
                'plans' => fn ($q) => $q
                    ->whereNull('category_id')
                    ->where('is_active', true)
                    ->where('is_public', true)
                    ->orderBy('sort_order')
                    ->with('activePrices'),
            ])
            ->firstOrFail();

        $mapPlan = fn (ProductPlan $plan): array => [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'features' => $plan->features ?? [],
            'prices' => $plan->activePrices->map(fn (ProductPlanPrice $pp): array => [
                'id' => $pp->id,
                'price' => (float) $pp->price,
                'interval_count' => $pp->interval_count,
                'interval_unit' => $pp->interval_unit,
                'interval_label' => $pp->interval_label,
                'stripe_price_id' => $pp->stripe_price_id,
                'label' => $pp->label,
                'is_default' => $pp->is_default,
            ])->values()->all(),
        ];

        $categories = $product->planCategories->map(fn ($cat): array => [
            'id' => $cat->id,
            'name' => $cat->name,
            'description' => $cat->description,
            'plans' => $cat->activePlans
                ->filter(fn (ProductPlan $p): bool => $p->is_public)
                ->map($mapPlan)
                ->values()
                ->all(),
        ])->values()->all();

        return response()->json([
            'product' => $product->name,
            'slug' => $product->slug,
            'categories' => $categories,
            // Plans without a category live here so the consumer can
            // still render them outside any grouped section.
            'plans' => $product->plans->map($mapPlan)->values()->all(),
        ]);
    }
}
