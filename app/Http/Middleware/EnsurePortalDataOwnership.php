<?php

namespace App\Http\Middleware;

use App\Models\PortalUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalDataOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        $portalUser = Auth::guard('portal')->user();

        if (! $portalUser instanceof PortalUser) {
            abort(401);
        }

        $customerId = $request->route('customer_id')
            ?? $request->route('customer')
            ?? $request->input('customer_id');

        if ($customerId !== null && (int) $customerId !== (int) $portalUser->customer_id) {
            abort(403, 'Forbidden — record does not belong to your account.');
        }

        return $next($request);
    }
}
