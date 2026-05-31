<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use App\Models\PortalUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Server-side SSO launcher for consumer products.
 *
 * The portal dashboard "Open Maavelus" button POSTs here. We:
 *
 *  1. Confirm the logged-in portal user actually has an active
 *     subscription for {slug} on their customer record.
 *  2. Mint a fresh Passport access token scoped to ['profile', {slug}].
 *  3. POST the token to the consumer app's SSO exchange endpoint
 *     (e.g. https://restaurant.maavelus.co.uk/wp-json/maavelus/v1/sso).
 *  4. Read the consumer's `redirect` field — a one-time URL that
 *     drops the visitor straight into the consumer app already
 *     logged in — and 302 the browser there.
 *
 * Why server-mint rather than ?sso=1 query-string:
 *   - The token never appears in the URL bar or referrer headers.
 *   - The consumer app holds the token in its own session storage,
 *     so the second leg (token -> user info) doesn't need the URL.
 *   - We log the launch exactly once with the audit trail attached
 *     to the originating portal user, not the customer aggregate.
 */
class ProductLaunchController extends Controller
{
    /**
     * Map a product slug to the consumer's token-exchange endpoint.
     * Returns null for products we don't know how to talk to —
     * the controller then surfaces a friendly "doesn't support
     * direct launch" message instead of a stray 404.
     */
    private function ssoEndpoint(string $slug): ?string
    {
        $base = match (true) {
            str_starts_with($slug, 'maavelus') => (string) config(
                'services.products.maavelus_url',
                'https://restaurant.maavelus.co.uk',
            ),
            in_array($slug, ['myorderpad', 'orderpad'], true) => (string) config(
                'services.products.myorderpad_url',
                'https://app.myorderpad.co.uk',
            ),
            default => null,
        };

        if ($base === null || $base === '') {
            return null;
        }

        // All consumer apps use the same WP-JSON namespace pattern.
        // We bake the path here rather than per-product config so a
        // consumer can't accidentally silo onto a non-standard route.
        return rtrim($base, '/').'/wp-json/maavelus/v1/sso';
    }

    public function launch(string $slug, Request $request): RedirectResponse|SymfonyResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        // Verify the customer actually has access to this product
        // BEFORE issuing the token. A scope alone wouldn't gate
        // anything if the consumer trusts our userinfo response
        // blindly — and minting tokens for products the user
        // doesn't have is a leak risk regardless.
        $cp = CustomerProduct::query()
            ->where('customer_id', $portalUser->customer_id)
            ->whereIn('status', ['active', 'trial'])
            ->whereHas('product', fn ($q) => $q->where('slug', $slug))
            ->with('product:id,name,slug')
            ->first();

        if ($cp === null) {
            return back()->with(
                'error',
                "You don't have an active subscription for this product.",
            );
        }

        $endpoint = $this->ssoEndpoint($slug);
        if ($endpoint === null) {
            return back()->with(
                'error',
                'This product does not support direct launch yet.',
            );
        }

        // Token scoped to ['profile', {slug}] — the consumer can
        // call /oauth/userinfo with this token to read the customer
        // record, but the per-product scope keeps it pinned to its
        // own surface. Token name carries enough info to make the
        // Connected Apps view useful ("launched maavelus from portal
        // at 2026-05-31 14:02").
        $token = $portalUser->createToken(
            'sso_launch:'.$slug,
            ['profile', $slug],
        )->accessToken;

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->post($endpoint, [
                    'powerhouse_token' => $token,
                    'customer_id' => $portalUser->customer_id,
                ]);
        } catch (\Throwable $e) {
            ActivityLog::create([
                'user_id' => $portalUser->id,
                'user_role' => 'portal',
                'action' => 'portal.sso_launch_failed',
                'entity_type' => 'customer_product',
                'entity_id' => $cp->id,
                'after' => [
                    'slug' => $slug,
                    'reason' => 'network_error',
                    'message' => substr($e->getMessage(), 0, 240),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);

            return back()->with(
                'error',
                'Could not reach '.($cp->product->name).'. Please try again in a moment.',
            );
        }

        if (! $response->successful()) {
            ActivityLog::create([
                'user_id' => $portalUser->id,
                'user_role' => 'portal',
                'action' => 'portal.sso_launch_failed',
                'entity_type' => 'customer_product',
                'entity_id' => $cp->id,
                'after' => [
                    'slug' => $slug,
                    'reason' => 'http_'.$response->status(),
                    'body' => substr((string) $response->body(), 0, 240),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);

            return back()->with(
                'error',
                'Could not launch '.($cp->product->name).'. Please try again.',
            );
        }

        $redirect = (string) $response->json('redirect', '');
        if ($redirect === '') {
            return back()->with(
                'error',
                'Launch failed: '.($cp->product->name).' didn\'t return a redirect URL.',
            );
        }

        ActivityLog::create([
            'user_id' => $portalUser->id,
            'user_role' => 'portal',
            'action' => 'portal.sso_launch',
            'entity_type' => 'customer_product',
            'entity_id' => $cp->id,
            'after' => [
                'slug' => $slug,
                'product_name' => $cp->product?->name,
                'customer_id' => $portalUser->customer_id,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        // Inertia::location() short-circuits Inertia's normal Vue
        // navigation and triggers a full browser redirect — exactly
        // what we want here because the destination is a different
        // origin (the consumer app), not part of our SPA.
        return Inertia::location($redirect);
    }
}
