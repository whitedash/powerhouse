<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\PortalUser;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            ->with(['product:id,name,slug,icon_colour', 'productPlan:id,name', 'planPrice:id,plan_id,price,interval_unit,interval_count'])
            ->get()
            ->map(fn (CustomerProduct $cp): array => [
                'id' => $cp->id,
                'product_name' => $cp->product?->name,
                'product_slug' => $cp->product?->slug,
                'icon_colour' => $cp->product?->icon_colour,
                'plan_name' => $cp->productPlan ? $cp->productPlan->name : 'Custom',
                'price' => round((float) ($cp->planPrice ? $cp->planPrice->price : ($cp->price_monthly ?? 0)), 2),
                'interval_label' => $cp->interval_label,
                'mrr' => round($cp->mrr_contribution, 2),
                'status' => $cp->status,
                'next_billing_date' => $cp->next_billing_date?->format('j M Y'),
                // SSO open URL. Consumer apps detect `sso=1` and
                // bounce the browser through /oauth/authorize. The
                // customer_id hint isn't trusted — it just speeds up
                // the consumer's "who is this?" lookup, the token
                // exchange is what actually proves identity.
                'sso_url' => $this->resolveSsoUrl($cp->product?->slug, $customer->id),
            ])
            ->all();

        // Connected apps — distinct OAuth clients holding non-revoked
        // tokens for any portal_user on this customer. We aggregate
        // across portal users so a single account view shows what
        // any user under the company has authorised.
        $portalUserIds = PortalUser::where('customer_id', $customer->id)->pluck('id')->all();

        $connectedApps = DB::table('oauth_access_tokens as t')
            ->join('oauth_clients as c', 'c.id', '=', 't.client_id')
            ->whereIn('t.user_id', $portalUserIds)
            ->where('t.revoked', false)
            ->where(function ($q) {
                $q->whereNull('t.expires_at')
                    ->orWhere('t.expires_at', '>', now());
            })
            ->select(
                't.client_id',
                'c.name as client_name',
                DB::raw('MAX(t.created_at) as last_authorized_at'),
                DB::raw('COUNT(*) as token_count'),
            )
            ->groupBy('t.client_id', 'c.name')
            ->orderByDesc('last_authorized_at')
            ->get()
            ->map(fn ($row): array => [
                'client_id' => $row->client_id,
                'name' => $row->client_name,
                'last_authorized_at' => $row->last_authorized_at,
                'token_count' => (int) $row->token_count,
            ])
            ->all();

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
            'connected_apps' => $connectedApps,
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
        if ($slug === null || $slug === '') {
            return null;
        }

        $base = match (true) {
            str_starts_with($slug, 'maavelus') => 'https://restaurant.maavelus.co.uk',
            $slug === 'myorderpad' => 'https://app.myorderpad.co.uk',
            $slug === 'orderpad' => 'https://app.myorderpad.co.uk',
            default => null,
        };

        return $base === null
            ? null
            : $base.'/?sso=1&customer_id='.$customerId;
    }
}
