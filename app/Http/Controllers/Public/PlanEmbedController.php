<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Support\PlanThemeTokens;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns the JavaScript embed widget for pricing plans
 * (PLANS-WIDGET-DESIGN.md §3a), in two flavours sharing one Blade IIFE:
 *
 *   GET /plans/{slug}/embed.js — a product's full pricing table
 *       <div id="pw-plans-{slug}"></div>
 *   GET /plan/{id}/embed.js    — ONE plan on its own (e.g. just "Pro"),
 *       all of that plan's active prices, none of its siblings
 *       <div id="pw-plan-{id}"></div>
 *
 * Mirrors EmbedController::script() for forms: application/javascript,
 * 5-minute public cache, open CORS — meant for arbitrary third-party
 * marketing sites. Only is_public + is_active plans of active products
 * ever reach a payload (public-IDOR stance: an invalid, private, or
 * retired id is an indistinguishable 404). Plan ids are already public
 * in the widget config, so the numeric single-plan URL exposes nothing
 * new. The checkout endpoint re-verifies all flags server-side, so the
 * cache can never sell a just-unpublished plan.
 */
class PlanEmbedController extends Controller
{
    public function script(string $slug): Response
    {
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'plans' => fn ($q) => $q->where('is_public', true)
                    ->where('is_active', true)
                    ->orderBy('sort_order'),
                'plans.activePrices',
            ])
            ->firstOrFail();

        return $this->respond($product, $product->plans, 'pw-plans-'.$product->slug);
    }

    public function planScript(int $id): Response
    {
        // One scoped query: a plan that is non-public, inactive, on an
        // inactive product, or nonexistent 404s identically.
        $plan = ProductPlan::whereKey($id)
            ->where('is_public', true)
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->with(['activePrices', 'product'])
            ->firstOrFail();

        /** @var Product $product */
        $product = $plan->product;

        return $this->respond($product, new Collection([$plan]), 'pw-plan-'.$plan->id);
    }

    /**
     * @param  Collection<int, ProductPlan>  $planRows
     */
    private function respond(Product $product, Collection $planRows, string $rootId): Response
    {
        $product->loadMissing('theme');

        $js = view('embed.plans-widget', [
            'product' => $product,
            'plan_rows' => $planRows,
            'root_id' => $rootId,
            // Resolved design tokens: the product's plan theme merged over
            // the defaults (un-themed = the widget's original look). The
            // same tokens feed the checkout session's branding_settings, so
            // widget steps and the Stripe payment step stay coherent.
            'tokens' => PlanThemeTokens::resolve($product->theme),
            // Both flavours check out through the product-scoped endpoint —
            // it validates the plan_price_id against the live catalog, so
            // it never needs to know which embed type initiated it.
            'checkout_url' => rtrim((string) config('app.url'), '/').'/plans/'.$product->slug.'/checkout',
            'stripe_key' => (string) config('services.stripe.key'),
            'turnstile_site_key' => (string) config('services.turnstile.site_key'),
        ])->render();

        return response($js)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
