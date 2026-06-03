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
        // Where to drop the operator back when they exit. Defaults to
        // the referrers list rather than the dashboard so exiting lands
        // them next to the "Preview" action they came from.
        $request->session()->put('referrer_preview_return_url', route('internal.referrers.index'));

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

    /**
     * Exit a referrer preview and restore the originating super_admin's
     * web-guard session. Because referrers and staff share the 'web'
     * guard, entering preview replaced the admin's session entirely —
     * exit must log the stored admin id back in rather than just log
     * out (which is what left operators stranded on the login screen).
     *
     * Sits outside role middleware: at exit time the session is the
     * referrer, but we immediately swap it back to the admin.
     */
    public function exitPreview(Request $request): RedirectResponse
    {
        $adminId = $request->session()->get('referrer_preview_admin_id');
        $returnUrl = $request->session()->get('referrer_preview_return_url', route('internal.dashboard'));

        // Drop impersonation state before the swap so a half-finished
        // exit can't leave the banner showing for the admin.
        $request->session()->forget([
            'referrer_preview_mode',
            'referrer_preview_admin_id',
            'referrer_preview_return_url',
        ]);

        // Only restore if the stored id still maps to a real super_admin
        // — guards against a stale/poisoned session id escalating.
        if ($adminId && User::where('id', $adminId)->where('role', 'super_admin')->exists()) {
            Auth::guard('web')->logout();
            $request->session()->regenerate();
            Auth::guard('web')->loginUsingId((int) $adminId);

            ActivityLog::create([
                'user_id' => (int) $adminId,
                'user_role' => 'super_admin',
                'action' => 'referrer.preview_exited',
                'entity_type' => User::class,
                'entity_id' => (int) $adminId,
                'after' => ['restored_admin_id' => (int) $adminId],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);

            return redirect($returnUrl)->with('success', 'Preview ended. You are back as admin.');
        }

        // Fallback: no admin id stored (direct hit / expired session).
        // Clear everything and send them to log in fresh.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('info', 'Preview session ended. Please log in again.');
    }
}
