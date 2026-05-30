<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PortalUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Revoke an OAuth client's access for this customer.
 *
 * Revoke semantics: we flip oauth_access_tokens.revoked to true
 * for every token issued to any portal_user under this customer
 * for the given client. That cuts off all current sessions
 * immediately; the consumer app will fail its next refresh.
 *
 * We don't delete the rows — keeping them around lets the audit
 * log + the dashboard's "last authorised" timestamp survive for
 * forensics (when did Maavelus actually get access, when was it
 * pulled). Passport's purge command can sweep them later.
 *
 * Scoping: we filter on user_id within the customer's portal_user
 * set so a contact under one account can't revoke a sibling
 * account's tokens — the customer_id check guards against that.
 */
class ConnectedAppController extends Controller
{
    public function revoke(string $clientId, Request $request): RedirectResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        // All portal users under the same customer — revoking spans
        // the account, not just the acting user. A revoking user is
        // saying "no app of this brand on our company" rather than
        // "no app for me personally".
        $portalUserIds = PortalUser::where('customer_id', $portalUser->customer_id)
            ->pluck('id')
            ->all();

        $revoked = DB::table('oauth_access_tokens')
            ->whereIn('user_id', $portalUserIds)
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->update(['revoked' => true, 'updated_at' => now()]);

        // Refresh tokens piggyback on the same access tokens — when
        // the access token is revoked the refresh token can't mint a
        // new one. We belt-and-brace by revoking any standalone
        // refresh tokens too so a future Passport bump doesn't
        // accidentally leave them live.
        DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', function ($q) use ($portalUserIds, $clientId) {
                $q->select('id')->from('oauth_access_tokens')
                    ->whereIn('user_id', $portalUserIds)
                    ->where('client_id', $clientId);
            })
            ->update(['revoked' => true]);

        ActivityLog::create([
            'user_id' => $portalUser->id,
            'user_role' => 'portal',
            'action' => 'oauth.client.revoked',
            'entity_type' => 'oauth_client',
            'entity_id' => null,
            'after' => [
                'client_id' => $clientId,
                'tokens_revoked' => $revoked,
                'customer_id' => $portalUser->customer_id,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', 'Access revoked. The app will be signed out shortly.');
    }
}
