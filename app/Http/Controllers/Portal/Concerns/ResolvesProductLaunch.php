<?php

namespace App\Http\Controllers\Portal\Concerns;

/**
 * Shared product-launch URL resolution for the portal. Both the
 * Dashboard ("Open" buttons) and Subscriptions ("Open {product}")
 * surfaces decide how to launch a consumer product — either a
 * server-minted SSO token exchange (/portal/launch/{slug}) or a plain
 * deep link. Keeping the slug→URL map in one place stops the two
 * controllers drifting apart.
 */
trait ResolvesProductLaunch
{
    /**
     * Map a product slug to the SSO entry point on the consumer app.
     * The hint `?sso=1&customer_id=X` tells the consumer to start an
     * OAuth flow; auth still happens through Powerhouse, this is just
     * the deep link. Unknown slugs return null.
     */
    protected function resolveSsoUrl(?string $slug, int $customerId): ?string
    {
        $base = $this->getProductUrl($slug);

        return $base === null
            ? null
            : $base.'/?sso=1&customer_id='.$customerId;
    }

    /**
     * Base URL for a consumer product. Pulled from config so a staging
     * install can point a product at a different hostname without code
     * changes. Returns null for slugs we don't yet know how to launch.
     */
    protected function getProductUrl(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return match (true) {
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
    }

    /**
     * True when the consumer app exposes a token-exchange SSO endpoint
     * we can POST to (see ProductLaunchController). When true the UI
     * POSTs to /portal/launch/{slug} (server-mint flow); when false it
     * falls back to sso_url / the subscriptions page.
     */
    protected function productHasSso(?string $slug): bool
    {
        if ($slug === null || $slug === '') {
            return false;
        }

        return in_array($slug, [
            'maavelus',
            'maavelus-hospitality',
            'myorderpad',
        ], true);
    }
}
