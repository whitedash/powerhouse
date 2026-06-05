<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=()'
        );

        // 'unsafe-eval' is only needed by Vite's dev/HMR runtime — the
        // compiled production Vue bundle never calls eval(). Drop it in
        // production so an injected payload can't reach eval()/new Function().
        // ('unsafe-inline' stays: Inertia's initial-page <script> and Vue
        // scoped styles are inline; removing it needs per-request nonces,
        // tracked as a follow-up.)
        // Cloudflare Turnstile (public submit-ticket) loads its widget script
        // from challenges.cloudflare.com and the analytics beacon from
        // static.cloudflareinsights.com.
        $cf = 'https://challenges.cloudflare.com https://static.cloudflareinsights.com';
        $scriptSrc = app()->environment('production')
            ? "script-src 'self' 'unsafe-inline' https://fonts.bunny.net https://js.stripe.com https://*.stripe.com {$cf}"
            : "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://fonts.bunny.net https://js.stripe.com https://*.stripe.com {$cf}";

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            // Stripe.js powers the embedded invoice checkout (portal).
            $scriptSrc,
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            // data: is required for FullCalendar's embedded `fcicons` font
            // (the prev/next chevron glyphs ship as a base64 data: URI).
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data: blob:",
            // Stripe API calls + the Embedded Checkout iframe (js.stripe.com
            // mounts frames from *.stripe.com / hooks.stripe.com).
            "connect-src 'self' https://api.stripe.com https://*.stripe.com",
            // Turnstile renders its challenge inside an iframe from
            // challenges.cloudflare.com.
            "frame-src 'self' https://*.stripe.com https://hooks.stripe.com https://challenges.cloudflare.com",
            "frame-ancestors 'none'",
        ]));

        if (app()->environment('production')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        // `X-Powered-By` is set by PHP's SAPI layer based on `expose_php`,
        // not via Symfony's Response, so we also strip it directly. The
        // production fix is `expose_php=Off` in php.ini; this is the belt.
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
