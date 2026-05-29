<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guest-only routes for the portal (login page, password reset).
 * If the portal session is already authenticated, bounce them to the
 * dashboard instead of letting them see the login form.
 */
class RedirectIfPortalAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('portal')->check()) {
            return redirect()->route('portal.dashboard');
        }

        return $next($request);
    }
}
