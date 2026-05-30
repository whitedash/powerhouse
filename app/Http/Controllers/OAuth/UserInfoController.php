<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PortalUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * OAuth /userinfo endpoint.
 *
 * Called by consumer apps (Maavelus, MyOrderPad, etc.) after they
 * exchange an authorization code for an access token. Returns the
 * customer record bound to the token holder, plus a per-product
 * access map so the consumer can decide whether to provision a
 * tenant on first SSO.
 *
 * Guard: 'api' (Passport) → resolves to a PortalUser. Customer is
 * looked up via portal_users.customer_id, so this works for every
 * contact under an account without any extra scope handling.
 *
 * Scope filtering: the token's scopes don't gate the response
 * shape today — every authenticated consumer sees the customer
 * record. That mirrors most SaaS userinfo endpoints (scopes gate
 * *access* to the endpoint, not field-level visibility). If we
 * later want per-scope redaction it goes here, scoped by
 * $request->user()->tokenCan('xxx').
 */
class UserInfoController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        /** @var PortalUser|null $portalUser */
        $portalUser = $request->user();

        if ($portalUser === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $customer = Customer::query()
            ->with([
                'primaryContact:id,customer_id,first_name,last_name,email,phone,is_primary',
                'customerProducts' => fn ($q) => $q
                    ->whereIn('status', ['active', 'trial'])
                    ->with([
                        'product:id,name,slug,icon_colour',
                        'productPlan:id,name',
                    ]),
            ])
            ->findOrFail($portalUser->customer_id);

        // Flat list of product slugs the customer can reach. Consumer
        // apps check membership rather than parsing the full product
        // tree (`if ("maavelus" in products)`).
        $productSlugs = $customer->customerProducts
            ->map(fn ($cp) => $cp->product?->slug)
            ->filter()
            ->values();

        // Convenience block for the Maavelus consumer. Other products
        // get the same treatment under their own key as they wire up.
        $maavelus = $customer->customerProducts
            ->first(fn ($cp) => $cp->product !== null
                && str_starts_with((string) $cp->product->slug, 'maavelus'));

        // primaryContact is a HasOne that can return null when no
        // contact is marked is_primary. Spell that out so phpstan
        // doesn't think the relation is non-nullable.
        $contactEmail = $customer->primaryContact !== null
            ? $customer->primaryContact->email
            : $portalUser->email;

        $lastLoginAt = $customer->portal_last_login_at;

        return response()->json([
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $contactEmail,
            'phone' => $customer->primaryContact?->phone,
            'company' => $customer->name,
            'products' => $productSlugs,
            'maavelus' => $maavelus === null ? null : [
                'customer_product_id' => $maavelus->id,
                'plan' => $maavelus->productPlan?->name,
                'status' => $maavelus->status,
            ],
            'portal_url' => rtrim((string) config('app.url'), '/').'/portal',
            // SSO engagement signal. Consumer apps can use it to
            // show a "first time here?" prompt vs. "welcome back".
            'portal_login_count' => $customer->portal_login_count,
            'portal_last_login_at' => $lastLoginAt instanceof Carbon
                ? $lastLoginAt->toIso8601String()
                : null,
        ]);
    }

    /**
     * Lighter-weight endpoint — just which products the token holder
     * has access to. Consumer apps poll this on dashboard render to
     * gate features without fetching the full userinfo payload.
     */
    public function products(Request $request): JsonResponse
    {
        /** @var PortalUser|null $portalUser */
        $portalUser = $request->user();

        if ($portalUser === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $customer = Customer::query()
            ->with([
                'customerProducts' => fn ($q) => $q
                    ->whereIn('status', ['active', 'trial'])
                    ->with('product:id,name,slug,icon_colour'),
            ])
            ->findOrFail($portalUser->customer_id);

        $products = $customer->customerProducts
            ->map(fn ($cp) => [
                'slug' => $cp->product?->slug,
                'name' => $cp->product?->name,
                'status' => $cp->status,
                'icon_colour' => $cp->product?->icon_colour,
            ])
            ->filter(fn (array $p): bool => $p['slug'] !== null)
            ->values();

        return response()->json([
            'customer_id' => $customer->id,
            'products' => $products,
        ]);
    }
}
