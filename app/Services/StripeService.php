<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\Stripe;

/**
 * Stripe Checkout integration for one-off invoice payments.
 *
 * We charge the *outstanding* balance (total − amount_paid) as a single
 * Checkout line item rather than re-itemising invoice lines. The invoice
 * total already bakes in per-line discounts and VAT, and supports partial
 * payments — re-deriving line items from unit_price would undercharge on
 * any invoice that carries a discount, VAT, or a prior part-payment. The
 * customer still has the itemised PDF; Checkout only collects the money.
 */
class StripeService
{
    /**
     * Build (or rebuild) a *hosted* Checkout session for an invoice's
     * outstanding balance, returning the full Session (with ->url / ->id).
     *
     * This is the redirect flow — used where we need a shareable URL the
     * customer can click from outside the portal: the staff "Generate
     * payment link" action and the "Pay Now" button on the invoice PDF /
     * email. The in-portal experience uses the embedded flow below instead.
     * The caller persists $session->url / $session->id onto the invoice.
     */
    public function createCheckoutSession(Invoice $invoice): Session
    {
        Stripe::setApiKey((string) config('services.stripe.secret'));

        return Session::create([
            ...$this->baseSessionParams($invoice),
            'success_url' => route('portal.invoices.paid', $invoice->id).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('portal.invoices.index'),
        ]);
    }

    /**
     * Build an *embedded* Checkout session and return only its
     * client_secret. This powers Stripe Embedded Checkout — the payment
     * form renders inline inside Powerhouse rather than redirecting to a
     * Stripe-hosted page. There is no ->url in embedded mode, so nothing is
     * stored: the secret is short-lived and fetched fresh each time the
     * customer opens the modal.
     */
    public function createEmbeddedCheckoutSession(Invoice $invoice): string
    {
        Stripe::setApiKey((string) config('services.stripe.secret'));

        $session = Session::create([
            ...$this->baseSessionParams($invoice),
            // Stripe renamed the embedded-form ui_mode: the old 'embedded'
            // value is now rejected ("no longer supported. Use 'embedded_page'
            // instead."). 'embedded_page' still returns a client_secret for
            // Stripe.js initEmbeddedCheckout().
            'ui_mode' => 'embedded_page',
            // Embedded returns the customer here once the form completes;
            // session_id lets the landing page confirm + settle on arrival.
            'return_url' => route('portal.invoices.paid', $invoice->id).'?session_id={CHECKOUT_SESSION_ID}',
        ]);

        return (string) $session->client_secret;
    }

    /**
     * Shared Checkout parameters for both the hosted and embedded flows —
     * everything except the mode-specific URL keys (success/cancel for
     * hosted, ui_mode/return_url for embedded).
     *
     * @return array<string, mixed>
     */
    private function baseSessionParams(Invoice $invoice): array
    {
        $outstanding = round((float) $invoice->total - (float) $invoice->amount_paid, 2);

        // Guard rail — never open Checkout for £0 (or negative). The
        // controllers gate on status too, but this keeps the service
        // honest if called directly.
        $unitAmount = (int) round($outstanding * 100);
        if ($unitAmount < 1) {
            throw new \RuntimeException("Invoice {$invoice->number} has no outstanding balance to charge.");
        }

        $invoice->loadMissing('customer.primaryContact');

        return [
            'payment_method_types' => ['card'],
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    // No per-invoice currency column — the business bills
                    // exclusively in GBP. Centralise the default here.
                    'currency' => 'gbp',
                    'unit_amount' => $unitAmount,
                    'product_data' => [
                        'name' => 'Invoice '.$invoice->number,
                    ],
                ],
                'quantity' => 1,
            ]],
            'customer_email' => $invoice->customer?->primaryContact?->email,
            'metadata' => [
                'invoice_id' => (string) $invoice->id,
                'customer_id' => (string) $invoice->customer_id,
            ],
            'client_reference_id' => (string) $invoice->id,
        ];
    }

    /**
     * Settle an invoice from a confirmed Stripe payment: flip status to
     * paid, stamp the Stripe identifiers, and auto-reinstate any products
     * the auto-suspension sweep pulled for non-payment — but only once the
     * customer carries no other unpaid invoices.
     *
     * Idempotency is the caller's job (webhook dedupe + the paid-status
     * short-circuit); this method assumes it should commit the payment.
     *
     * @return array<int, CustomerProduct> the products that were reinstated
     */
    public function markInvoicePaid(Invoice $invoice, string $sessionId, string $paymentIntentId): array
    {
        $reinstated = DB::transaction(function () use ($invoice, $sessionId, $paymentIntentId): array {
            $invoice->update([
                'status' => 'paid',
                'amount_paid' => $invoice->total,
                'paid_at' => now(),
                'payment_method' => 'card',
                'paid_via' => 'stripe',
                'stripe_checkout_session_id' => $sessionId,
                'stripe_payment_intent_id' => $paymentIntentId,
            ]);

            ActivityLog::create([
                'user_id' => null,
                'user_role' => 'system',
                'action' => 'invoice.paid',
                'entity_type' => 'invoice',
                'entity_id' => $invoice->id,
                'after' => [
                    'number' => $invoice->number,
                    'total' => (float) $invoice->total,
                    'method' => 'stripe',
                    'payment_intent' => $paymentIntentId,
                ],
                'ip_address' => null,
                'user_agent' => 'stripe-webhook',
            ]);

            return $this->autoReinstate($invoice);
        });

        return $reinstated;
    }

    /**
     * Reinstate products the system auto-suspended for non-payment, when
     * the customer no longer has any unpaid invoices. Mirrors the manual
     * reinstate in CustomerProductController and the auto-suspend sweep in
     * ProcessSuspensions: we only undo *system* (suspended_by = null),
     * non-payment suspensions — a deliberate staff suspension stays put.
     *
     * Runs inside the markInvoicePaid transaction. Webhook side effects
     * (WHM unsuspend) fire via the dispatcher; customer email is sent by
     * the caller after commit.
     *
     * @return array<int, CustomerProduct>
     */
    private function autoReinstate(Invoice $invoice): array
    {
        // Any remaining unpaid invoice keeps the suspension in place.
        $stillOwes = Invoice::where('customer_id', $invoice->customer_id)
            ->whereIn('status', ['sent', 'overdue', 'partially_paid'])
            ->where('id', '!=', $invoice->id)
            ->exists();

        if ($stillOwes) {
            return [];
        }

        $suspended = CustomerProduct::where('customer_id', $invoice->customer_id)
            ->where('status', 'suspended')
            ->where('suspension_reason', 'non_payment')
            ->whereNull('suspended_by') // null = system-suspended
            // product + productPlan + customer.primaryContact are all read
            // downstream (dispatchReinstatement, the reinstatement email),
            // so eager-load them — preventLazyLoading() is on outside prod.
            ->with(['product', 'productPlan', 'customer.primaryContact'])
            ->get();

        $dispatcher = app(WebhookDispatcher::class);
        $reinstated = [];

        foreach ($suspended as $cp) {
            $before = ['status' => $cp->status];

            $cp->update([
                'status' => 'active',
                'reinstated_at' => now(),
                'reinstated_by' => null, // null = system-reinstated
                'reinstatement_reason' => 'Auto-reinstated: invoice '.$invoice->number.' paid, account settled.',
                'suspension_reason' => null,
                'suspended_at' => null,
                'suspended_by' => null,
            ]);

            // Fires the product webhook + unsuspends WHM/cPanel accounts.
            $dispatcher->dispatchReinstatement($cp);

            ActivityLog::create([
                'user_id' => null,
                'user_role' => 'system',
                'action' => 'customer_product.auto_reinstated',
                'entity_type' => 'customer',
                'entity_id' => $cp->customer_id,
                'before' => $before,
                'after' => [
                    'customer_product_id' => $cp->id,
                    'product' => $cp->product?->name,
                    'trigger' => 'invoice_paid',
                    'invoice_number' => $invoice->number,
                ],
                'ip_address' => null,
                'user_agent' => 'stripe-webhook',
            ]);

            $reinstated[] = $cp;
        }

        return $reinstated;
    }
}
