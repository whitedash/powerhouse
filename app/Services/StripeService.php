<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\StripeCustomer;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;
use Stripe\Customer as StripeCustomerApi;
use Stripe\PaymentMethod as StripePaymentMethodApi;
use Stripe\SetupIntent;
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
        $this->configureStripe();

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
        $this->configureStripe();

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
     * Set the Stripe API key and fail closed if a *test* key is configured in
     * production. This guard used to live in AppServiceProvider::boot(), but
     * throwing there took the entire application (and the deploy's
     * config:cache/route:cache steps) down — health checks included. Here it
     * only fires when we are about to actually move money, which is the only
     * place a test key matters: it stops live checkout from silently running
     * against Stripe test mode (customer "pays", invoice flips to paid,
     * product reinstates, but no money ever moves) while leaving the rest of
     * the site fully operational.
     */
    private function configureStripe(): void
    {
        $secret = (string) config('services.stripe.secret');

        if (app()->isProduction() && str_starts_with($secret, 'sk_test_')) {
            throw new \RuntimeException(
                'Refusing to charge: a Stripe TEST key (sk_test_) is configured in production.'
            );
        }

        Stripe::setApiKey($secret);
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

        $params = [
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
            'metadata' => [
                'invoice_id' => (string) $invoice->id,
                'customer_id' => (string) $invoice->customer_id,
            ],
            'client_reference_id' => (string) $invoice->id,
        ];

        if ($invoice->customer !== null) {
            // Attach to a Stripe Customer and VAULT the card for reuse (P1).
            // setup_future_usage=off_session saves the card against the customer
            // so P2 can charge it off-session — we do NOT charge off-session here.
            $params['customer'] = $this->ensureStripeCustomer($invoice->customer);
            $params['payment_intent_data'] = ['setup_future_usage' => 'off_session'];
        }
        // (Invoices always carry a customer, so the vault branch above always
        // runs; no customer = a plain on-session payment with no email prefilled.)

        return $params;
    }

    /**
     * Find or create the Stripe Customer for this customer (single GBP
     * account) and persist the mapping. Returns the stripe_customer_id.
     */
    public function ensureStripeCustomer(Customer $customer): string
    {
        $existing = StripeCustomer::where('customer_id', $customer->id)->first();
        if ($existing !== null) {
            return $existing->stripe_customer_id;
        }

        $this->configureStripe();

        $customer->loadMissing('primaryContact');
        $stripeCustomer = StripeCustomerApi::create([
            'name' => $customer->name,
            'email' => $customer->primaryContact?->email,
            'metadata' => ['customer_id' => (string) $customer->id],
        ]);

        StripeCustomer::create([
            'customer_id' => $customer->id,
            'stripe_customer_id' => $stripeCustomer->id,
        ]);

        return $stripeCustomer->id;
    }

    /**
     * Create a SetupIntent for the portal "add a card" flow. The card is
     * collected client-side (Stripe Elements) against this client_secret and
     * attached to the customer's Stripe Customer for off-session reuse.
     */
    public function createSetupIntent(Customer $customer): string
    {
        $this->configureStripe();

        $intent = SetupIntent::create([
            'customer' => $this->ensureStripeCustomer($customer),
            'payment_method_types' => ['card'],
            'usage' => 'off_session',
        ]);

        return (string) $intent->client_secret;
    }

    /**
     * Persist a vaulted card's SAFE metadata. Pure DB — the card itself stays
     * in Stripe. First active card for the customer becomes the default.
     * Idempotent on stripe_payment_method_id (a re-vault updates in place).
     *
     * @param  array{brand?: ?string, last4?: ?string, exp_month?: ?int, exp_year?: ?int}  $card
     */
    public function recordPaymentMethod(Customer $customer, string $stripeCustomerId, string $stripePaymentMethodId, array $card): PaymentMethod
    {
        return DB::transaction(function () use ($customer, $stripeCustomerId, $stripePaymentMethodId, $card): PaymentMethod {
            $hasActive = PaymentMethod::where('customer_id', $customer->id)
                ->where('status', 'active')
                ->where('stripe_payment_method_id', '!=', $stripePaymentMethodId)
                ->exists();

            return PaymentMethod::updateOrCreate(
                ['stripe_payment_method_id' => $stripePaymentMethodId],
                [
                    'customer_id' => $customer->id,
                    'stripe_customer_id' => $stripeCustomerId,
                    'brand' => $card['brand'] ?? null,
                    'last4' => $card['last4'] ?? null,
                    'exp_month' => $card['exp_month'] ?? null,
                    'exp_year' => $card['exp_year'] ?? null,
                    'status' => 'active',
                    // First card on file is the default.
                    'is_default' => ! $hasActive,
                ],
            );
        });
    }

    /**
     * Retrieve a payment method from Stripe and vault its safe metadata,
     * confirming it is attached to the customer's own Stripe Customer (so a
     * crafted pm id can't bind another customer's card). Used by the portal
     * add-a-card flow.
     */
    public function recordPaymentMethodFromStripe(Customer $customer, string $stripePaymentMethodId): PaymentMethod
    {
        $this->configureStripe();

        $stripeCustomerId = $this->ensureStripeCustomer($customer);
        $pm = StripePaymentMethodApi::retrieve($stripePaymentMethodId);

        // Attach if the SetupIntent flow didn't already; reject a PM that
        // belongs to a different Stripe customer.
        if (empty($pm->customer)) {
            $pm = $pm->attach(['customer' => $stripeCustomerId]);
        } elseif ((string) $pm->customer !== $stripeCustomerId) {
            throw new \RuntimeException('Payment method does not belong to this customer.');
        }

        return $this->recordPaymentMethod($customer, $stripeCustomerId, (string) $pm->id, [
            'brand' => $pm->card->brand ?? null,
            'last4' => $pm->card->last4 ?? null,
            'exp_month' => $pm->card->exp_month ?? null,
            'exp_year' => $pm->card->exp_year ?? null,
        ]);
    }

    /**
     * Detach a saved card from Stripe (best-effort — the local row is the
     * source of truth for display; a Stripe hiccup shouldn't block removal).
     */
    public function detachPaymentMethod(string $stripePaymentMethodId): void
    {
        $this->configureStripe();

        $pm = StripePaymentMethodApi::retrieve($stripePaymentMethodId);
        $pm->detach();
    }

    /**
     * Vault the card used in a completed Checkout session (setup_future_usage)
     * so it's reusable in P2. Best-effort: a vaulting hiccup must NEVER break
     * payment reconciliation, so the webhook calls this in a guarded block.
     */
    public function vaultCardFromSession(Invoice $invoice, string $sessionId): void
    {
        if ($invoice->customer === null) {
            return;
        }

        $this->configureStripe();

        $session = Session::retrieve(['id' => $sessionId, 'expand' => ['payment_intent']]);
        $stripeCustomerId = $session->customer ? (string) $session->customer : null;
        $pi = $session->payment_intent;
        $paymentMethodId = is_object($pi) ? ($pi->payment_method ?? null) : null;

        if ($stripeCustomerId === null || $paymentMethodId === null) {
            return;
        }

        // Keep the mapping in sync with whatever Stripe used for the session.
        StripeCustomer::firstOrCreate(
            ['customer_id' => $invoice->customer_id],
            ['stripe_customer_id' => $stripeCustomerId],
        );

        $pm = StripePaymentMethodApi::retrieve((string) $paymentMethodId);
        $this->recordPaymentMethod($invoice->customer, $stripeCustomerId, (string) $pm->id, [
            'brand' => $pm->card->brand ?? null,
            'last4' => $pm->card->last4 ?? null,
            'exp_month' => $pm->card->exp_month ?? null,
            'exp_year' => $pm->card->exp_year ?? null,
        ]);
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
        $transitioned = false;
        $reinstated = DB::transaction(function () use ($invoice, $sessionId, $paymentIntentId, &$transitioned): array {
            // Re-read the invoice under a row lock and re-check status INSIDE
            // the transaction. Stripe fires checkout.session.completed and
            // payment_intent.succeeded for the same payment, retries
            // deliveries, and the success-page confirm path is a third
            // concurrent settler. The callers' status short-circuit happens
            // before this call, so without the lock two in-flight settles can
            // both pass it and double-run autoReinstate() — duplicate WHM
            // unsuspends, duplicate reinstatement emails. The lock serialises
            // them; the loser sees status === 'paid' and no-ops.
            $invoice = Invoice::whereKey($invoice->getKey())->lockForUpdate()->first();

            if (! $invoice || $invoice->status === 'paid') {
                return [];
            }

            $invoice->update([
                'status' => 'paid',
                'amount_paid' => $invoice->total,
                'paid_at' => now(),
                'payment_method' => 'card',
                'paid_via' => 'stripe',
                'stripe_checkout_session_id' => $sessionId,
                'stripe_payment_intent_id' => $paymentIntentId,
            ]);

            // Ledger row for this settle (Billing P1) — every settle is recorded
            // so the ledger is live before P2's off-session charging.
            Payment::create([
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'amount' => $invoice->total,
                'currency' => 'gbp',
                'rail' => 'stripe',
                'stripe_payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : null,
                'status' => 'succeeded',
                'attempted_at' => now(),
                'created_by' => null,
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

            $transitioned = true;

            return $this->autoReinstate($invoice);
        });

        // Accrue referral commission AFTER commit, and ONLY on the real
        // unpaid→paid transition (the idempotent no-op path leaves
        // $transitioned false, so webhook retries never re-accrue). Guarded
        // so a commission failure can NEVER roll back or break the payment.
        if ($transitioned) {
            try {
                app(CommissionService::class)->accrueForInvoice($invoice);
            } catch (\Throwable $e) {
                report($e);
            }
        }

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
