<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\PortalUser;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal landing page. Pulls everything the customer needs to see
 * "where am I right now" — active subscriptions, the last few
 * invoices, outstanding balance, and an open-tickets counter. All
 * queries are scoped to the portal user's customer_id; staff data
 * never leaks into the response.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $customer = Customer::forPortalUser($portalUser->customer_id)
            ->with(['primaryContact:id,customer_id,name,email'])
            ->firstOrFail();

        $activeProducts = CustomerProduct::where('customer_id', $customer->id)
            ->whereIn('status', ['active', 'trial'])
            ->with(['product:id,name,slug,icon_colour,description', 'productPlan:id,name', 'planPrice:id,plan_id,price,interval_unit,interval_count'])
            ->get()
            ->map(fn (CustomerProduct $cp): array => [
                'id' => $cp->id,
                'product_name' => $cp->product?->name,
                'product_slug' => $cp->product?->slug,
                'product_description' => $cp->product?->description,
                'icon_colour' => $cp->product?->icon_colour,
                'plan_name' => $cp->productPlan ? $cp->productPlan->name : 'Custom',
                'price' => round((float) ($cp->planPrice ? $cp->planPrice->price : ($cp->price_monthly ?? 0)), 2),
                'interval_label' => $cp->interval_label,
                'mrr' => round($cp->mrr_contribution, 2),
                'status' => $cp->status,
                'next_billing_date' => $cp->next_billing_date?->format('j M Y'),
                // Plain SSO entry point (browser-driven `?sso=1`
                // bounce). Retained as a fallback for products that
                // ship without a server-side token exchange endpoint.
                'sso_url' => $this->resolveSsoUrl($cp->product?->slug, $customer->id),
                // sso_enabled = the consumer app exposes a
                // /wp-json/{vendor}/v1/sso endpoint that accepts a
                // freshly-minted Passport token and returns a
                // one-time login URL. When true, the Dashboard.vue
                // "Open" button POSTs to /portal/launch/{slug}
                // (token-mint flow). When false, it falls back to
                // sso_url / the subscriptions page.
                'sso_enabled' => $this->productHasSso($cp->product?->slug),
                // Direct URL surfaced as `launch_url` for UI clarity
                // — same value as sso_url today; kept under the new
                // name so the Vue layer can phase out sso_url next
                // sprint without breaking the API shape now.
                'launch_url' => $this->getProductUrl($cp->product?->slug),
            ])
            ->all();

        // The connected-apps roll-up moved to /portal/security so we
        // skip the join here. Dashboard is the launching surface; the
        // Security page owns token management.

        $recentInvoices = Invoice::where('customer_id', $customer->id)
            ->with('billingEntity:id,name')
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn (Invoice $inv): array => [
                'id' => $inv->id,
                'number' => $inv->number,
                'description' => $inv->billingEntity ? $inv->billingEntity->name : 'Invoice',
                'total' => round((float) $inv->total, 2),
                'status' => $inv->status,
                'due_date' => $inv->due_date?->format('j M Y'),
                'is_overdue' => $inv->status === 'overdue',
            ])
            ->all();

        $openTickets = SupportTicket::where('customer_id', $customer->id)
            ->whereIn('status', ['open', 'in_progress', 'awaiting_customer'])
            ->count();

        $invoicesPaid = Invoice::where('customer_id', $customer->id)
            ->where('status', 'paid')
            ->count();

        $outstandingTotal = (float) Invoice::where('customer_id', $customer->id)
            ->whereIn('status', ['sent', 'overdue'])
            ->sum('total');

        return Inertia::render('Portal/Dashboard', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'city' => $customer->city,
                'primary_email' => $customer->primaryContact?->email,
                'member_since' => $customer->created_at?->format('M Y'),
                'contact_name' => $customer->primaryContact?->name,
            ],
            'active_products' => $activeProducts,
            'recent_invoices' => $recentInvoices,
            'invoices_paid_count' => $invoicesPaid,
            'outstanding_total' => round($outstandingTotal, 2),
            'open_tickets' => $openTickets,
        ]);
    }

    /**
     * Map a product slug to the SSO entry point on the consumer app.
     * The hint `?sso=1&customer_id=X` tells the consumer to start an
     * OAuth flow; auth still happens through Powerhouse, this is just
     * the deep link.
     *
     * Unknown slugs return null — the dashboard falls back to the
     * existing "Manage" link to the portal subscription page.
     */
    private function resolveSsoUrl(?string $slug, int $customerId): ?string
    {
        $base = $this->getProductUrl($slug);

        return $base === null
            ? null
            : $base.'/?sso=1&customer_id='.$customerId;
    }

    /**
     * Base URL for a consumer product. Pulled from config so a
     * staging install can point Maavelus at a different hostname
     * without code changes. Returns null for slugs we don't yet
     * know how to launch — the UI then falls back to the legacy
     * "Manage" link to /portal/subscriptions.
     */
    private function getProductUrl(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return match (true) {
            str_starts_with($slug, 'maavelus') => (string) config(
                'services.products.maavelus_url',
                'https://restaurant.maavelus.co.uk',
            ),
            in_array($slug, ['myorderpad', 'orderpad'], true) => (string) config(
                'services.products.myorderpad_url',
                'https://app.myorderpad.co.uk',
            ),
            default => null,
        };
    }

    /**
     * True when the consumer app exposes a token-exchange SSO
     * endpoint we can POST to (see ProductLaunchController). Used
     * by the Dashboard.vue Open button to decide whether to POST
     * to /portal/launch/{slug} (server-mint flow) or follow the
     * sso_url directly (legacy redirect).
     */
    private function productHasSso(?string $slug): bool
    {
        if ($slug === null || $slug === '') {
            return false;
        }

        return in_array($slug, [
            'maavelus',
            'maavelus-hospitality',
            'myorderpad',
        ], true);
    }
}
