<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bounces an authenticated referrer away from staff-only chrome
 * (the entire /internal area + the root dashboard) and into their
 * partner portal. Without this, a referrer could land on
 * /customers / /invoices / etc. and the role:staff,super_admin
 * middleware on those routes would 403 them — confusing UX, and
 * the 403 page leaks the existence of internal routes. Sending
 * them straight to /referrer/dashboard is friendlier and tighter.
 *
 * Use as a route middleware on the *internal* group only — not on
 * the referrer routes themselves, or you'd loop.
 */
class RedirectIfReferrer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user && $user->role === 'referrer') {
            return redirect()->route('referrer.dashboard');
        }

        return $next($request);
    }
}
