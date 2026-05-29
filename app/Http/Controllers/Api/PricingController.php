<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPlan;
use Illuminate\Http\JsonResponse;

class PricingController extends Controller
{
    /**
     * Public pricing endpoint consumed by product marketing sites
     * (myorderpad.co.uk, etc.). Returns only active+public plans so
     * staff can stage a plan internally before exposing it.
     *
     * CORS is handled by config/cors.php (paths includes api/*; only
     * APP_URL / PORTAL_URL / REFERRER_URL origins are allowed).
     */
    public function __invoke(string $slug): JsonResponse
    {
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $plans = ProductPlan::where('product_id', $product->id)
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ProductPlan $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'price_monthly' => (float) $p->price_monthly,
                'price_annual' => $p->price_annual !== null ? (float) $p->price_annual : null,
                'annual_monthly_cost' => $p->annual_monthly_cost,
                'savings_percent' => $p->savings_percent,
                'features' => $p->features ?? [],
                'stripe_price_id_monthly' => $p->stripe_price_id_monthly,
                'stripe_price_id_annual' => $p->stripe_price_id_annual,
            ])
            ->values()
            ->all();

        return response()->json([
            'product' => $product->name,
            'slug' => $product->slug,
            'plans' => $plans,
        ]);
    }
}
