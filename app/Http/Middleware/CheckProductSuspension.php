<?php

namespace App\Http\Middleware;

use App\Http\Controllers\OAuth\SuspensionController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspension gate for the OAuth authorize endpoint.
 *
 * Attached to the named `passport.authorizations.authorize` route in
 * AppServiceProvider (the same hook RequirePkce uses). When a portal
 * user is signed in and their subscription for the requesting product
 * is suspended, we short-circuit with the branded "Account suspended"
 * page; otherwise the request falls through to Passport untouched, so
 * the normal consent flow is never disturbed.
 */
class CheckProductSuspension
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth('portal')->check()) {
            $suspended = app(SuspensionController::class)->suspensionResponse($request);

            if ($suspended !== null) {
                return $suspended->toResponse($request);
            }
        }

        return $next($request);
    }
}
