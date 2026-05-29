<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PortalUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
