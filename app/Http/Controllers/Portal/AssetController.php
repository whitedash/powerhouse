<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesProductLaunch;
use App\Models\CustomerProduct;
use App\Models\Domain;
use App\Models\PortalUser;
use App\Models\Website;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company-facing READ-ONLY asset view (Stage 4): the customer's Websites
 * (+ hosting), Domains and Services in one place — mirroring the internal
 * Assets grouping but customer-friendly.
 *
 * Two non-negotiables:
 *   - Every query is scoped to the portal user's OWN customer_id; there is no
 *     path to another customer's data.
 *   - Only customer-safe fields are serialised. cPanel/WHM credentials, internal
 *     notes, GA4/Cloudflare IDs, provisioning fields (external_user_id,
 *     oauth_client_id, wp_user_id, config), Stripe ids, billing-entity / cost
 *     internals are NEVER included. We show what the customer PAYS, never
 *     internal cost or entity internals.
 *
 * Read-only: this controller adds no add/edit/suspend actions.
 */
class AssetController extends Controller
{
    use ResolvesProductLaunch;

    public function index(): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();
        $customerId = (int) $portalUser->customer_id;

        return Inertia::render('Portal/Assets', [
            'websites' => $this->websites($customerId),
            'domains' => $this->domains($customerId),
            'services' => $this->services($customerId),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function websites(int $customerId): array
    {
        return Website::where('customer_id', $customerId)
            ->with([
                'plan:id,name',
                'planPrice:id,plan_id,price,interval_count,interval_unit,label',
                'domain:id,domain,ssl_status',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Website $w): array => [
                'id' => $w->id,
                'name' => $w->name,
                'url' => $w->url,
                // Hosting: plan + tier (= what they pay) + lifecycle, never the
                // cPanel/WHM/billing internals.
                'hosting_plan' => $w->plan?->name,
                'hosting_tier' => $w->planPrice?->display_label,
                'hosting_status' => $w->hosting_status,
                'ssl' => $this->normaliseSsl($w->domain?->ssl_status),
                // Their own site's performance scores (safe to surface).
                'pagespeed_mobile' => $w->pagespeed_mobile,
                'pagespeed_desktop' => $w->pagespeed_desktop,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function domains(int $customerId): array
    {
        return Domain::where('customer_id', $customerId)
            ->with(['plan:id', 'plan.activePrices'])
            ->orderBy('domain')
            ->get()
            ->map(function (Domain $d): array {
                // Renewal info = what they'd pay on renewal, from the matched
                // domain plan's active tier (only when actually self-renewing).
                $renewal = null;
                $tier = $d->auto_renew ? $d->plan?->activePrices->first() : null;
                if ($tier !== null) {
                    $renewal = [
                        'price' => round((float) $tier->price, 2),
                        'interval' => $tier->interval_label,
                    ];
                }

                return [
                    'id' => $d->id,
                    'domain' => $d->domain,
                    'expiry_date' => $d->expiry_date?->format('j M Y'),
                    'auto_renew' => (bool) $d->auto_renew,
                    'ssl' => $this->normaliseSsl($d->ssl_status),
                    'renewal' => $renewal,
                ];
            })
            ->all();
    }

    /**
     * Genuine services only — a CP whose plan is is_hosting / is_domain is an
     * asset shown above, not a service (defensive; there should be none).
     *
     * @return array<int, array<string, mixed>>
     */
    private function services(int $customerId): array
    {
        return CustomerProduct::where('customer_id', $customerId)
            ->whereIn('status', ['active', 'trial', 'suspended'])
            ->whereDoesntHave('productPlan', fn ($q) => $q->where(
                fn ($qq) => $qq->where('is_hosting', true)->orWhere('is_domain', true)
            ))
            ->with([
                'product:id,name,slug,icon_colour',
                'productPlan:id,name',
                'planPrice:id,plan_id,price,interval_unit,interval_count',
            ])
            ->get()
            ->map(fn (CustomerProduct $cp): array => [
                'id' => $cp->id,
                'product_name' => $cp->product?->name,
                'product_slug' => $cp->product?->slug,
                'icon_colour' => $cp->product?->icon_colour,
                'plan_name' => $cp->productPlan ? $cp->productPlan->name : 'Custom',
                // What the customer pays — never internal cost / entity.
                'price' => round((float) ($cp->planPrice ? $cp->planPrice->price : ($cp->price_monthly ?? 0)), 2),
                'interval_label' => $cp->interval_label,
                'status' => $cp->status,
                'next_billing_date' => $cp->next_billing_date?->format('j M Y'),
                // SaaS SSO launch preserved (server-mint via /portal/launch/{slug}
                // when supported, else the plain deep link).
                'sso_enabled' => $this->productHasSso($cp->product?->slug),
                'sso_url' => $this->resolveSsoUrl($cp->product?->slug, $customerId),
            ])
            ->all();
    }

    /**
     * Map an internal SSL status to a customer-facing validity word.
     */
    private function normaliseSsl(?string $status): string
    {
        return match ($status) {
            'active', 'expiring' => 'valid',
            'expired' => 'expired',
            default => 'unknown',
        };
    }
}
