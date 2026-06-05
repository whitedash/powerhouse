<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProductKey;
use App\Http\Controllers\Controller;
use App\Models\Referrer;
use App\Services\ReferralCodeGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The canonical referral hub link: GET /r/{code}.
 *
 * Validates the code, logs a referral_clicks row, drops a first-party
 * signed wd_ref cookie (60-day), then 302s to the product destination
 * resolved from `p`. Unknown codes redirect to the default destination
 * WITHOUT logging — we neither confirm nor deny a code's validity, and we
 * never write a click for a code that resolves to no referrer.
 *
 * Public + rate-limited (route-level throttle) since it writes a row per
 * hit.
 */
class ReferralRedirectController extends Controller
{
    public function __invoke(string $code, Request $request): RedirectResponse
    {
        $code = ReferralCodeGenerator::normalise($code);

        // Resolve `p` against ProductKey; unknown/empty is simply ignored.
        $product = ProductKey::tryFromString($request->query('p'));

        $destination = $this->destinationFor($product);

        $referrer = Referrer::where('referral_code', $code)
            ->where('is_active', true)
            ->first();

        // Unknown/inactive code: neutral redirect, no click logged.
        if ($referrer === null) {
            return redirect()->away($destination);
        }

        // Campaign tag — length-capped, free text.
        $campaign = $request->query('c');
        if (is_string($campaign)) {
            $campaign = mb_substr($campaign, 0, (int) config('referrals.campaign_max', 100));
        } else {
            $campaign = null;
        }

        $referrer->clicks()->create([
            'referral_code' => $code,
            'product' => $product?->value,
            'campaign' => $campaign,
            'utm_source' => $this->str($request->query('utm_source'), 255),
            'utm_medium' => $this->str($request->query('utm_medium'), 255),
            'utm_campaign' => $this->str($request->query('utm_campaign'), 255),
            'landing_url' => $this->str($request->fullUrl(), 2048),
            'ip_address' => $request->ip(),
            'user_agent' => $this->str($request->userAgent(), 512),
        ]);

        // Forward ref + p + c to the destination so product-side capture
        // (Phase 3) can read them from the URL even without the cookie.
        $query = array_filter([
            'ref' => $code,
            'p' => $product?->value,
            'c' => $campaign,
        ], fn ($v) => $v !== null && $v !== '');

        $target = $destination.(str_contains($destination, '?') ? '&' : '?').http_build_query($query);

        // First-party signed cookie on the hub domain, 60-day TTL.
        $minutes = (int) config('referrals.window_days', 60) * 24 * 60;

        return redirect()->away($target)
            ->withCookie(cookie((string) config('referrals.cookie_name', 'wd_ref'), $code, $minutes));
    }

    private function destinationFor(?ProductKey $product): string
    {
        $map = (array) config('referrals.destinations', []);
        $default = (string) config('referrals.default', config('app.url'));

        if ($product === null) {
            return $default;
        }

        return (string) ($map[$product->value] ?? $default);
    }

    private function str(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
