<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PortalUser;
use App\Models\Referrer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Issues short-lived signed tokens that grant a super_admin a
 * read-as session on the portal or referrer side. The token never
 * grants direct access — it's a one-shot key handed to the preview
 * endpoint on the *other* guard, which then logs the operator in
 * as the target user.
 *
 * Why a token rather than just a redirect with `?as=`:
 *
 *  - The portal and referrer surfaces sit on *different* guards
 *    (`portal` and `web`). A plain redirect can't carry a logged-in
 *    session across guards.
 *  - A token survives a window.open into a new tab.
 *  - A token can be revoked centrally (cache delete) and self-expires
 *    even if cache isn't drained.
 *
 * 10-minute TTL is plenty for "click → new tab opens → I'm in" while
 * being short enough that a leaked URL isn't dangerous for long.
 */
class ImpersonationController extends Controller
{
    private const TTL_MINUTES = 10;

    public function portalPreview(int $customerId, Request $request): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);

        // Pick the first portal user for this customer — there's
        // typically only one. If we ever need to disambiguate, the UI
        // can offer a contact picker before calling this endpoint.
        $portalUser = PortalUser::where('customer_id', $customer->id)->first();
        if (! $portalUser) {
            return response()->json([
                'error' => "{$customer->name} has no portal access yet. Invite a contact to the portal first.",
            ], 422);
        }

        $token = $this->mintToken('portal', [
            'portal_user_id' => $portalUser->id,
        ]);

        $this->log($request, 'impersonation.portal_preview', 'customer', $customer->id, [
            'customer_id' => $customer->id,
            'portal_user_id' => $portalUser->id,
        ]);

        return response()->json([
            'url' => url('/portal/preview').'?'.http_build_query([
                'token' => $token,
                'pid' => $portalUser->id,
            ]),
        ]);
    }

    public function referrerPreview(int $referrerId, Request $request): JsonResponse
    {
        $referrer = Referrer::with('user')->findOrFail($referrerId);

        if (! $referrer->user) {
            return response()->json([
                'error' => 'This referrer has no linked user account.',
            ], 422);
        }

        $token = $this->mintToken('referrer', [
            'user_id' => $referrer->user_id,
        ]);

        $this->log($request, 'impersonation.referrer_preview', 'referrer', $referrer->id, [
            'referrer_id' => $referrer->id,
            'user_id' => $referrer->user_id,
        ]);

        return response()->json([
            'url' => url('/referrer/preview').'?'.http_build_query([
                'token' => $token,
                'uid' => $referrer->user_id,
            ]),
        ]);
    }

    /**
     * Mint a random opaque token and stash the payload behind it in
     * cache. Returning the random plaintext is fine because the cache
     * is the only place the payload lives — the token itself doesn't
     * encode anything sensitive.
     *
     * @param  array<string, int|string>  $payload
     */
    private function mintToken(string $type, array $payload): string
    {
        $token = Str::random(64);

        Cache::put(
            'preview_token_'.$token,
            array_merge($payload, [
                'type' => $type,
                'issued_by_user_id' => (int) (auth()->id() ?? 0),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES)->timestamp,
            ]),
            now()->addMinutes(self::TTL_MINUTES),
        );

        return $token;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function log(Request $request, string $action, string $entityType, int $entityId, array $after): void
    {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
