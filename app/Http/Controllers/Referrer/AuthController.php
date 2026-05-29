<?php

namespace App\Http\Controllers\Referrer;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Referrer-side preview entrypoint. Referrers don't have a dedicated
 * login screen — they share the staff /login + role-aware redirect —
 * so this controller exists solely to consume an impersonation token
 * and establish a web-guard session on behalf of the referrer.
 */
class AuthController extends Controller
{
    public function preview(Request $request): RedirectResponse
    {
        $token = (string) $request->query('token', '');
        $uid = (int) $request->query('uid', 0);

        if (! $token || ! $uid) {
            abort(403, 'Invalid preview link.');
        }

        $payload = Cache::get('preview_token_'.$token);

        if (! is_array($payload)
            || ($payload['type'] ?? null) !== 'referrer'
            || (int) ($payload['user_id'] ?? 0) !== $uid
            || (int) ($payload['expires_at'] ?? 0) < now()->timestamp
        ) {
            abort(403, 'Preview link expired or invalid. Generate a new one from the Referrers page.');
        }

        $referrerUser = User::where('id', $uid)->where('role', 'referrer')->firstOrFail();

        // Force a logout-then-login so the originating super_admin's
        // web session is replaced cleanly. This is intentional — the
        // operator opens preview in a NEW tab, so their other tabs
        // (which share the cookie) will start reading as the referrer
        // too. We accept that tradeoff because the alternative is
        // building a parallel guard just for preview, which is a lot
        // of complexity for a rarely-used staff tool.
        Auth::guard('web')->logout();
        $request->session()->regenerate();
        Auth::guard('web')->login($referrerUser);

        Cache::forget('preview_token_'.$token);

        $request->session()->put('referrer_preview_mode', true);
        $request->session()->put('referrer_preview_admin_id', $payload['issued_by_user_id'] ?? null);

        ActivityLog::create([
            'user_id' => $referrerUser->id,
            'user_role' => 'referrer',
            'action' => 'referrer.preview_entered',
            'entity_type' => User::class,
            'entity_id' => $referrerUser->id,
            'after' => [
                'preview_admin_id' => $payload['issued_by_user_id'] ?? null,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return redirect()->route('referrer.dashboard');
    }
}
