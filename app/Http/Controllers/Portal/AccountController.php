<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PortalUser;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            'password_meta' => [
                'last_changed_at' => $this->lastPasswordChangeAt($portalUser->id)?->toIso8601String(),
            ],
            'tokens' => $this->connectedApps($portalUser->customer_id),
        ]);
    }

    /**
     * Active OAuth tokens for the whole customer (aggregated across every
     * portal user under the account, mirroring the dashboard roll-up).
     * Moved here from the now-merged Security page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function connectedApps(int $customerId): array
    {
        $portalUserIds = PortalUser::where('customer_id', $customerId)->pluck('id')->all();

        return DB::table('oauth_access_tokens as t')
            ->join('oauth_clients as c', 'c.id', '=', 't.client_id')
            ->whereIn('t.user_id', $portalUserIds)
            ->where('t.revoked', false)
            ->where(function ($q) {
                $q->whereNull('t.expires_at')->orWhere('t.expires_at', '>', now());
            })
            ->select('t.id', 't.name', 't.scopes', 't.created_at', 't.expires_at', 'c.name as client_name')
            ->orderByDesc('t.created_at')
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->id,
                'name' => $row->name ?: $row->client_name,
                'client_name' => $row->client_name,
                'scopes' => $this->decodeScopes((string) ($row->scopes ?? '')),
                'created_at' => $row->created_at,
                'expires_at' => $row->expires_at,
            ])
            ->values()
            ->all();
    }

    public function revokeToken(string $tokenId, Request $request): RedirectResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        // Token must belong to a portal user under the same customer —
        // lookup-by-(id + user_id-in-set) before flipping revoked, so a
        // guessed id from another tenant can't be revoked.
        $portalUserIds = PortalUser::where('customer_id', $portalUser->customer_id)->pluck('id')->all();

        $row = DB::table('oauth_access_tokens')
            ->where('id', $tokenId)
            ->whereIn('user_id', $portalUserIds)
            ->where('revoked', false)
            ->first();

        if ($row === null) {
            return back()->with('error', 'That token is already revoked or does not belong to your account.');
        }

        DB::table('oauth_access_tokens')->where('id', $tokenId)->update(['revoked' => true, 'updated_at' => now()]);
        DB::table('oauth_refresh_tokens')->where('access_token_id', $tokenId)->update(['revoked' => true]);

        ActivityLog::create([
            'user_id' => $portalUser->id,
            'user_role' => 'portal',
            'action' => 'oauth.token.revoked',
            'entity_type' => 'oauth_access_token',
            'entity_id' => null,
            'after' => ['token_id' => $tokenId, 'client_id' => $row->client_id],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', 'Token revoked.');
    }

    /**
     * Most recent portal.password_changed event for this user.
     */
    private function lastPasswordChangeAt(int $portalUserId): ?Carbon
    {
        return ActivityLog::where('user_id', $portalUserId)
            ->where('user_role', 'portal')
            ->where('action', 'portal.password_changed')
            ->orderByDesc('created_at')
            ->first()?->created_at;
    }

    /**
     * @return array<int, string>
     */
    private function decodeScopes(string $scopes): array
    {
        if ($scopes === '') {
            return [];
        }
        $decoded = json_decode($scopes, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
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
