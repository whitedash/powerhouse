<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PortalUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal session auth. Uses the 'portal' guard so portal sessions
 * are isolated from staff sessions — a staff user signed into the
 * internal app and a customer signed into the portal can coexist
 * on the same browser without colliding.
 */
class AuthController extends Controller
{
    public function showLogin(): Response
    {
        return Inertia::render('Portal/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::guard('portal')->attempt($credentials, $remember)) {
            return back()
                ->withInput($request->only('email', 'remember'))
                ->withErrors(['email' => 'Invalid email or password.']);
        }

        $request->session()->regenerate();

        /** @var PortalUser $user */
        $user = Auth::guard('portal')->user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->logActivity($request, 'portal.login_succeeded', $user);

        return redirect()->intended(route('portal.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::guard('portal')->user();

        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user instanceof PortalUser) {
            $this->logActivity($request, 'portal.logout', $user);
        }

        return redirect()
            ->route('portal.login')
            ->with('success', 'You have been signed out.');
    }

    /**
     * Token-gated preview entrypoint. The staff side calls
     * /impersonate/portal/{id} to mint a token, then opens
     * /portal/preview?token=… in a new tab. This handler consumes
     * the token (single-use — deleted on success), logs the
     * super_admin in as the target portal user, and tags the
     * session as preview mode so the layout shows a banner.
     *
     * Sits OUTSIDE the portal_guest middleware: a staff member
     * might still hold a portal session from earlier, and we want
     * to flip that session to the new user rather than redirect
     * back to the dashboard.
     */
    public function preview(Request $request): RedirectResponse
    {
        $token = (string) $request->query('token', '');
        $pid = (int) $request->query('pid', 0);

        if (! $token || ! $pid) {
            abort(403, 'Invalid preview link.');
        }

        $payload = Cache::get('preview_token_'.$token);

        if (! is_array($payload)
            || ($payload['type'] ?? null) !== 'portal'
            || (int) ($payload['portal_user_id'] ?? 0) !== $pid
            || (int) ($payload['expires_at'] ?? 0) < now()->timestamp
        ) {
            abort(403, 'Preview link expired or invalid. Generate a new one from the customer detail page.');
        }

        $portalUser = PortalUser::findOrFail($pid);

        // Drop any existing portal session before the swap so the
        // CSRF token regenerates and the previous user can't be
        // resurrected via back-button.
        Auth::guard('portal')->logout();
        $request->session()->regenerate();

        Auth::guard('portal')->login($portalUser);

        // Single-use — token can't be replayed.
        Cache::forget('preview_token_'.$token);

        // Session flags survive page navigation inside the preview
        // tab. The admin id lets us record on logout who originated
        // the preview if we ever want a "return to admin" UX.
        $request->session()->put('portal_preview_mode', true);
        $request->session()->put('portal_preview_admin_id', $payload['issued_by_user_id'] ?? null);

        ActivityLog::create([
            'user_id' => $portalUser->id,
            'user_role' => 'portal',
            'action' => 'portal.preview_entered',
            'entity_type' => PortalUser::class,
            'entity_id' => $portalUser->id,
            'after' => [
                'preview_admin_id' => $payload['issued_by_user_id'] ?? null,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return redirect()->route('portal.dashboard');
    }

    private function logActivity(Request $request, string $action, PortalUser $user): void
    {
        ActivityLog::create([
            'user_id' => $user->id,
            'user_role' => 'portal',
            'action' => $action,
            'entity_type' => PortalUser::class,
            'entity_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
