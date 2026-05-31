<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PortalUser;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal /security page — password change + connected apps.
 *
 * The password endpoint already exists on AccountController; the
 * Security page POSTs to the same URL (portal.account.password) so
 * we don't fork the validation rules across two controllers. The
 * token revoke handler lives here because it's local to this page.
 *
 * Connected apps are scoped to the *customer* (every portal user
 * under the customer sees + can revoke any client). The OAuth
 * sprint set this pattern up; we preserve it for consistency.
 *
 * Token revoke is per-row (a single oauth_access_tokens.id) rather
 * than per-client. That's a finer-grained surface than the
 * "Revoke access" button on the dashboard, which kills every
 * token for a client. Both endpoints coexist — the security page
 * shows individual tokens with last-used + scope info; the
 * dashboard shows the rolled-up app card.
 */
class SecurityController extends Controller
{
    public function index(): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        // Aggregate across portal users under the same customer so
        // a contact under a single account can see what every user
        // in the company has authorised (mirrors the dashboard's
        // connected_apps roll-up).
        $portalUserIds = PortalUser::where('customer_id', $portalUser->customer_id)
            ->pluck('id')
            ->all();

        $tokens = DB::table('oauth_access_tokens as t')
            ->join('oauth_clients as c', 'c.id', '=', 't.client_id')
            ->whereIn('t.user_id', $portalUserIds)
            ->where('t.revoked', false)
            ->where(function ($q) {
                $q->whereNull('t.expires_at')
                    ->orWhere('t.expires_at', '>', now());
            })
            ->select(
                't.id',
                't.name',
                't.scopes',
                't.created_at',
                't.expires_at',
                't.user_id',
                'c.id as client_id',
                'c.name as client_name',
            )
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
            ->values();

        return Inertia::render('Portal/Security', [
            'password_meta' => [
                'last_changed_at' => $this->lastPasswordChangeAt($portalUser->id)?->toIso8601String(),
            ],
            'tokens' => $tokens,
        ]);
    }

    public function revokeToken(string $tokenId, Request $request): RedirectResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        // Token must belong to a portal user under the same customer.
        // Without this check a malicious user could revoke any
        // token id by guessing — we lookup-by-(id + user_id-in-set)
        // and only then flip the revoked flag.
        $portalUserIds = PortalUser::where('customer_id', $portalUser->customer_id)
            ->pluck('id')
            ->all();

        $row = DB::table('oauth_access_tokens')
            ->where('id', $tokenId)
            ->whereIn('user_id', $portalUserIds)
            ->where('revoked', false)
            ->first();

        if ($row === null) {
            return back()->withErrors([
                'token' => 'That token is already revoked or does not belong to your account.',
            ]);
        }

        DB::table('oauth_access_tokens')
            ->where('id', $tokenId)
            ->update(['revoked' => true, 'updated_at' => now()]);

        // Paired refresh token belt-and-brace, same pattern as the
        // ConnectedAppController's whole-client revoke.
        DB::table('oauth_refresh_tokens')
            ->where('access_token_id', $tokenId)
            ->update(['revoked' => true]);

        ActivityLog::create([
            'user_id' => $portalUser->id,
            'user_role' => 'portal',
            'action' => 'oauth.token.revoked',
            'entity_type' => 'oauth_access_token',
            'entity_id' => null,
            'after' => [
                'token_id' => $tokenId,
                'client_id' => $row->client_id,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', 'Token revoked.');
    }

    /**
     * Most recent portal.password_changed event for this user.
     * Used by Security.vue to render "Last changed N days ago".
     */
    private function lastPasswordChangeAt(int $portalUserId): ?Carbon
    {
        $row = ActivityLog::where('user_id', $portalUserId)
            ->where('user_role', 'portal')
            ->where('action', 'portal.password_changed')
            ->orderByDesc('created_at')
            ->first();

        return $row?->created_at;
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
}
