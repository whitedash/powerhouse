<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesProductLaunch;
use App\Models\Company;
use App\Models\CustomerProduct;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\PortalUser;
use App\Models\SupportTicket;
use App\Models\Website;
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
    use ResolvesProductLaunch;

    public function __invoke(): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $customer = Company::forPortalUser($portalUser->customer_id)
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

        // The connected-apps roll-up lives on /portal/account so we
        // skip the join here. Dashboard is the launching surface; the
        // Account page owns token management.

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

        $awaitingReply = SupportTicket::where('customer_id', $customer->id)
            ->where('status', 'awaiting_customer')
            ->count();

        // A short list for the overview's "Support tickets" card — the
        // newest non-closed tickets, with a customer-facing reference.
        $recentTickets = SupportTicket::where('customer_id', $customer->id)
            ->where('status', '!=', 'closed')
            ->orderByDesc('updated_at')
            ->take(4)
            ->get()
            ->map(fn (SupportTicket $t): array => [
                'id' => $t->id,
                'reference' => 'TK-'.$t->id,
                'subject' => $t->subject,
                'status' => $t->status,
                'status_label' => $this->ticketStatusLabel($t->status),
                'awaiting_reply' => $t->status === 'awaiting_customer',
                'updated_at' => $t->updated_at?->diffForHumans(),
            ])
            ->all();

        $invoicesPaid = Invoice::where('customer_id', $customer->id)
            ->where('status', 'paid')
            ->count();

        // Lifetime paid total — the "£X lifetime" foot on the paid stat card.
        $invoicesPaidTotal = (float) Invoice::where('customer_id', $customer->id)
            ->where('status', 'paid')
            ->sum('total');

        $outstandingInvoices = Invoice::where('customer_id', $customer->id)
            ->whereIn('status', ['sent', 'overdue']);
        $outstandingTotal = (float) (clone $outstandingInvoices)->sum('total');
        // Count of unpaid invoices (what the attention banner means by
        // "need payment") vs overdue-only (the urgent nav badge).
        $outstandingCount = (clone $outstandingInvoices)->count();

        // The single invoice the attention banner names ("INV-0006 is
        // awaiting payment, due …") and the outstanding stat foot points
        // at — the oldest unpaid, so the most pressing one surfaces first.
        $nextDue = (clone $outstandingInvoices)
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();

        $overdueCount = Invoice::where('customer_id', $customer->id)
            ->where('status', 'overdue')
            ->count();

        // Fuller asset picture (Stage 4) — all scoped to this customer. Genuine
        // services only (a hosting/domain CP is an asset, not a service).
        $assetCounts = [
            'websites' => Website::where('customer_id', $customer->id)->count(),
            'domains' => Domain::where('customer_id', $customer->id)->count(),
            'services' => CustomerProduct::where('customer_id', $customer->id)
                ->whereIn('status', ['active', 'trial'])
                ->whereDoesntHave('productPlan', fn ($q) => $q->where(
                    fn ($qq) => $qq->where('is_hosting', true)->orWhere('is_domain', true)
                ))
                ->count(),
        ];

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
            'asset_counts' => $assetCounts,
            'recent_invoices' => $recentInvoices,
            'recent_tickets' => $recentTickets,
            'invoices_paid_count' => $invoicesPaid,
            'invoices_paid_total' => round($invoicesPaidTotal, 2),
            'outstanding_total' => round($outstandingTotal, 2),
            'outstanding_count' => $outstandingCount,
            'overdue_count' => $overdueCount,
            'next_due_invoice' => $nextDue ? [
                'id' => $nextDue->id,
                'number' => $nextDue->number,
                'total' => round((float) $nextDue->total, 2),
                'due_date' => $nextDue->due_date?->format('j M Y'),
            ] : null,
            'open_tickets' => $openTickets,
            'awaiting_reply' => $awaitingReply,
        ]);
    }

    /**
     * Company-facing label for a ticket status. awaiting_customer means
     * the ball is in their court, so it reads "Reply needed".
     */
    private function ticketStatusLabel(string $status): string
    {
        return match ($status) {
            'in_progress' => 'In progress',
            'awaiting_customer' => 'Reply needed',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
            default => 'Open',
        };
    }
}
