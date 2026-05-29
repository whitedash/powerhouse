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
            'recent_invoices' => $recentInvoices,
            'invoices_paid_count' => $invoicesPaid,
            'outstanding_total' => round($outstandingTotal, 2),
            'open_tickets' => $openTickets,
        ]);
    }
}
