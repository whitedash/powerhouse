<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service profile for staff + super_admin users. Sits under
 * /account in the internal app so the user-menu "My account" item
 * has somewhere to land.
 *
 * Mirrors Portal\AccountController in shape (profile + password)
 * but stays on the web guard since staff don't authenticate through
 * the portal session.
 */
class MyAccountController extends Controller
{
    public function show(): Response
    {
        $user = Auth::user();

        return Inertia::render('Internal/Account', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'avatar_colour' => $user->avatar_colour,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->diffForHumans(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $before = ['name' => $user->name, 'email' => $user->email];

        $user->forceFill($data)->save();

        ActivityLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'action' => 'account.updated',
            'entity_type' => $user::class,
            'entity_id' => $user->id,
            'before' => $before,
            'after' => $data,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'confirmed',
                // Same strong-password rule as the portal — staff
                // shouldn't get an easier ride than customers.
                Password::min(10)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That current password isn\'t right.',
            ]);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        ActivityLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'action' => 'account.password_changed',
            'entity_type' => $user::class,
            'entity_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', 'Password updated.');
    }
}
