<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Open, credential-less CORS for the PUBLIC embeddable form endpoints
 * (forms/{slug}/embed.js + forms/{slug}/submit).
 *
 * These widgets are meant to be embedded on arbitrary third-party sites,
 * so the origin allow-list is "*". That is incompatible with the global
 * config/cors.php policy, which sets supports_credentials:true for the
 * cookie-based api/oauth/portal surfaces — and "*" + credentials is
 * forbidden by browsers. So forms get their own policy here, deliberately
 * WITHOUT Access-Control-Allow-Credentials.
 *
 * This mirrors what EmbedController::script() already does for embed.js
 * (Allow-Origin:*), now shared across the whole forms public group.
 *
 * CORS is not a security boundary for these routes: the submit endpoint
 * is unauthenticated, CSRF-exempt, uses credentials:'omit' from the
 * widget, and is guarded by a honeypot + per-IP rate limit. Opening CORS
 * does not widen the trust surface.
 */
class FormCors
{
    public function handle(Request $request, Closure $next): Response
    {
        // Preflight: answer OPTIONS directly so the browser clears the
        // actual POST. No credentials, so a bare "*" origin is valid.
        if ($request->getMethod() === 'OPTIONS') {
            return response('', 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Accept',
                'Access-Control-Max-Age' => '86400',
                'Vary' => 'Origin',
            ]);
        }

        $response = $next($request);

        // Actual request: open the response to any embedding origin.
        // No Allow-Credentials header — clients post with credentials:'omit'.
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $this->appendVaryOrigin($response);

        return $response;
    }

    /**
     * Append "Origin" to Vary without clobbering an existing Vary value
     * (e.g. an Inertia response that already varies on X-Inertia).
     */
    private function appendVaryOrigin(Response $response): void
    {
        $vary = $response->headers->get('Vary');

        if ($vary === null || $vary === '') {
            $response->headers->set('Vary', 'Origin');

            return;
        }

        if (stripos($vary, 'origin') === false) {
            $response->headers->set('Vary', $vary.', Origin');
        }
    }
}
