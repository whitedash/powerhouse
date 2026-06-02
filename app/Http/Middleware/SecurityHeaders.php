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

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            // Stripe.js powers the embedded invoice checkout (portal).
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://fonts.bunny.net https://js.stripe.com https://*.stripe.com",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            // data: is required for FullCalendar's embedded `fcicons` font
            // (the prev/next chevron glyphs ship as a base64 data: URI).
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data: blob:",
            // Stripe API calls + the Embedded Checkout iframe (js.stripe.com
            // mounts frames from *.stripe.com / hooks.stripe.com).
            "connect-src 'self' https://api.stripe.com https://*.stripe.com",
            "frame-src 'self' https://*.stripe.com https://hooks.stripe.com",
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
