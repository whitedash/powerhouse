<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPlanPrice;
use App\Services\PlanPurchaseService;
use App\Services\StripeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Anonymous checkout initiation for the Plans widget
 * (PLANS-WIDGET-DESIGN.md §3b). The visitor picks a plan_price_id and
 * supplies name/email; we resolve the price SERVER-SIDE (never trusting
 * the embed's cached config or any client amount), then return the
 * client_secret of an embedded Stripe Checkout session. NO database
 * writes happen here — provisioning is webhook-only, so an abandoned
 * checkout leaves zero orphan rows.
 *
 * Abuse controls, layered: honeypot (silent fake-accept), per-IP-per-slug
 * rate limit at the form-submit tier, route throttle, and Cloudflare
 * Turnstile verified server-side (same pattern as the public /support
 * intake) — each bot hit past these costs a Stripe API call, hence the
 * extra gate the forms endpoints don't carry.
 */
class PlanCheckoutController extends Controller
{
    public function create(
        string $slug,
        Request $request,
        PlanPurchaseService $plans,
        StripeService $stripe,
    ): JsonResponse {
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        // Honeypot: a hidden field humans never touch. Bots get a shaped
        // success without a client_secret — nothing to proceed with, and
        // nothing to learn from (forms-widget posture).
        if ($request->filled('_hp')) {
            return response()->json(['received' => true]);
        }

        // Same tier as form submits: 5 accepted attempts per hour per IP
        // per slug. Checked before the Turnstile round-trip and the Stripe
        // call so a hammering IP costs us nothing external.
        $rateKey = 'plan_checkout_'.$product->slug.'_'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return response()->json([
                'errors' => ['plan_price_id' => ['Too many attempts — please try again later.']],
            ], 429);
        }

        if (! $this->turnstilePasses($request)) {
            return response()->json([
                'errors' => ['captcha' => ['Verification failed — please try again.']],
            ], 422);
        }

        $data = $request->validate([
            'plan_price_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        // Re-verify the catalog flags on the LIVE rows — the embed script
        // is cached for 5 minutes and its payload is attacker-editable
        // anyway. A non-public/inactive plan or price 422s even when
        // requested directly.
        $price = ProductPlanPrice::query()
            ->whereKey((int) $data['plan_price_id'])
            ->where('is_active', true)
            ->whereHas('plan', fn ($q) => $q->where('product_id', $product->id)
                ->where('is_public', true)
                ->where('is_active', true))
            ->with('plan.product')
            ->first();

        if ($price === null) {
            return response()->json([
                'errors' => ['plan_price_id' => ['This plan is not available.']],
            ], 422);
        }

        RateLimiter::hit($rateKey, 3600);

        // Gross total (price + entity VAT) from the same calculator the
        // webhook uses to build the invoice — charge and ledger can't drift.
        $totals = $plans->totals($price);

        $clientSecret = $stripe->createPlanCheckoutSession(
            $price,
            $data['name'],
            $data['email'],
            $totals['total'],
            $product->slug,
        );

        return response()->json(['client_secret' => $clientSecret]);
    }

    /**
     * Post-payment landing for the embedded Checkout's return leg. Pure
     * presentation: the webhook is the only provisioner, so this page
     * deliberately reads/writes nothing about the session.
     */
    public function purchased(string $slug): View
    {
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return view('public.plan-purchased', ['product' => $product]);
    }

    /**
     * Verify the Turnstile token server-side. Same implementation as the
     * public /support intake (SupportTicketController::turnstilePasses):
     * bypass is phpunit/keyless-local-dev only and MUST stay false in
     * production.
     */
    private function turnstilePasses(Request $request): bool
    {
        if (config('services.turnstile.bypass')) {
            return true;
        }

        $token = (string) $request->input('cf-turnstile-response');
        if ($token === '') {
            return false;
        }

        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('services.turnstile.secret'),
            'response' => $token,
            'remoteip' => $request->ip(),
        ]);

        return $response->ok() && ($response->json('success') === true);
    }
}
