<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns the JavaScript embed widget for a product's pricing plans
 * (PLANS-WIDGET-DESIGN.md §3a). Mirrors EmbedController::script() for
 * forms: same delivery (application/javascript IIFE), same 5-minute
 * public cache, same open CORS — the widget is meant for arbitrary
 * third-party marketing sites.
 *
 *   <div id="pw-plans-{slug}"></div>
 *   <script src="https://hub.whitedash.com/plans/{slug}/embed.js"></script>
 *
 * Only is_public + is_active plans (with active prices) ever reach the
 * payload — the public-IDOR stance the KB and forms take. The checkout
 * endpoint re-verifies the same flags server-side, so the 5-minute cache
 * can never sell a just-unpublished plan.
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

        $js = view('embed.plans-widget', [
            'product' => $product,
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
