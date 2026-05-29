<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PortalUser;
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
 * Portal account self-management. The portal user can update their
 * own name + email, and change their password (current-password
 * required). Customer-level fields (company name, address) are
 * staff-managed — the portal exposes them read-only.
 */
class AccountController extends Controller
{
    public function index(): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $customer = Customer::forPortalUser($portalUser->customer_id)
            ->with('primaryContact:id,customer_id,name,email,phone')
            ->firstOrFail();

        return Inertia::render('Portal/Account', [
            'portal_user' => [
                'id' => $portalUser->id,
                'name' => $portalUser->name,
                'email' => $portalUser->email,
                'last_login_at' => $portalUser->last_login_at?->diffForHumans(),
            ],
            'customer' => [
                'name' => $customer->name,
                'city' => $customer->city,
                'address_line1' => $customer->address_line1,
                'postcode' => $customer->postcode,
                'country' => $customer->country,
                'primary_contact_email' => $customer->primaryContact?->email,
                'primary_contact_phone' => $customer->primaryContact?->phone,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('portal_users', 'email')->ignore($portalUser->id),
            ],
        ]);

        $before = ['name' => $portalUser->name, 'email' => $portalUser->email];

        $portalUser->name = $data['name'];
        $portalUser->email = $data['email'];
        $portalUser->save();

        ActivityLog::create([
            'user_id' => $portalUser->id,
            'user_role' => 'portal',
            'action' => 'portal.profile_updated',
            'entity_type' => PortalUser::class,
            'entity_id' => $portalUser->id,
            'before' => $before,
            'after' => ['name' => $portalUser->name, 'email' => $portalUser->email],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'confirmed',
                // Match the reset-link rule so a user changing their
                // password while signed in faces the same strength bar
                // as one going through forgot-password.
                Password::min(10)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        if (! Hash::check($data['current_password'], $portalUser->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That current password isn\'t right.',
            ]);
        }

        $portalUser->password = $data['password']; // cast 'hashed' handles bcrypt
        $portalUser->save();

        ActivityLog::create([
            'user_id' => $portalUser->id,
            'user_role' => 'portal',
            'action' => 'portal.password_changed',
            'entity_type' => PortalUser::class,
            'entity_id' => $portalUser->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', 'Password updated.');
    }
}
