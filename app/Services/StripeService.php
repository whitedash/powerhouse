<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\StripeCustomer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Customer as StripeCustomerApi;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
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

        // The customer-identity keys come from one deterministic builder that
        // returns EITHER `customer` OR `customer_email` (never both), so the
        // Stripe conflict that 503'd live checkout cannot be reconstructed here.
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
            'metadata' => [
                'invoice_id' => (string) $invoice->id,
                'customer_id' => (string) $invoice->customer_id,
            ],
            'client_reference_id' => (string) $invoice->id,
            ...$this->checkoutCustomerParams($invoice),
        ];
    }

    /**
     * Build the customer-identity portion of a Checkout session.
     *
     * Returns EXACTLY ONE of two mutually exclusive shapes, so passing both
     * `customer` and `customer_email` — which Stripe rejects ("you may only
     * specify one of these parameters: customer, customer_email") and which
     * 503'd live checkout — is impossible by construction rather than by a
     * null-guard that a later edit could undo:
     *
     *   - vault:    ['customer' => <id>, 'payment_intent_data' => ['setup_future_usage' => 'off_session']]
     *   - fallback: ['customer_email' => <email|null>]
     *
     * Vaulting the card is a nice-to-have; taking payment is essential. If
     * resolving the Stripe Customer fails for ANY reason (restricted key,
     * transient Stripe error, the unique-constraint race) we fall back to the
     * email-only session so the customer can still pay — a designed, observable
     * fallback (logged + Sentry-alerted), never a silent swallow.
     *
     * @return array<string, mixed>
     */
    private function checkoutCustomerParams(Invoice $invoice): array
    {
        $customer = $invoice->customer;

        if ($customer !== null) {
            try {
                return [
                    'customer' => $this->resolveStripeCustomer($customer),
                    'payment_intent_data' => ['setup_future_usage' => 'off_session'],
                ];
            } catch (\Throwable $e) {
                $this->reportCustomerResolveFailure($invoice, $e);
                // fall through to the email-only session below
            }
        }

        return ['customer_email' => $customer?->primaryContact?->email];
    }

    /**
     * Record a Stripe-Customer resolution failure that forced an email-only
     * checkout. The customer can still pay; we just couldn't vault the card —
     * which we want visibility on, so it is both logged and sent to Sentry.
     */
    private function reportCustomerResolveFailure(Invoice $invoice, \Throwable $e): void
    {
        Log::warning('stripe.customer_resolve_failed', [
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'error' => $e->getMessage(),
        ]);

        if (function_exists('Sentry\captureException')) {
            \Sentry\captureException($e);
        }
    }

    /**
     * Resolve THE Stripe Customer for a Powerhouse customer — the single
     * idempotent, race-safe entry point shared by invoice checkout and the
     * portal add-card flow, so there is exactly one Stripe Customer per
     * customer (single GBP account). Returns the stripe_customer_id.
     *
     * Idempotency is enforced at two layers:
     *   1. Mapping-first: an existing stripe_customers row short-circuits with
     *      no Stripe call at all.
     *   2. On create, a deterministic Stripe idempotency key derived from the
     *      customer id means concurrent/retried creates return the SAME Stripe
     *      Customer rather than minting duplicates.
     *
     * The DB mapping has a UNIQUE(customer_id). If two requests both miss the
     * mapping and race to insert, one wins and the other catches the unique
     * violation and re-reads the winner — so callers always converge on one
     * Stripe Customer even under concurrency.
     */
    public function resolveStripeCustomer(Customer $customer): string
    {
        $existing = StripeCustomer::where('customer_id', $customer->id)->first();
        if ($existing !== null) {
            return $existing->stripe_customer_id;
        }

        $stripeCustomerId = $this->createStripeCustomer($customer);

        try {
            StripeCustomer::create([
                'customer_id' => $customer->id,
                'stripe_customer_id' => $stripeCustomerId,
            ]);

            return $stripeCustomerId;
        } catch (QueryException $e) {
            // Lost the insert race: another request persisted the mapping first.
            // UNIQUE(customer_id) guarantees a single row — re-read and use the
            // winner. (Both creates used the same idempotency key, so it is the
            // same Stripe Customer regardless.)
            $winner = StripeCustomer::where('customer_id', $customer->id)->first();
            if ($winner !== null) {
                return $winner->stripe_customer_id;
            }

            throw $e;
        }
    }

    /**
     * Create the Stripe Customer object (no DB write). Isolated as its own seam
     * so resolveStripeCustomer's mapping/race logic is testable without the
     * Stripe network. The deterministic idempotency key makes concurrent or
     * retried creates for the same customer return one Stripe Customer.
     */
    protected function createStripeCustomer(Customer $customer): string
    {
        $this->configureStripe();

        $customer->loadMissing('primaryContact');
        $stripeCustomer = StripeCustomerApi::create(
            [
                'name' => $customer->name,
                'email' => $customer->primaryContact?->email,
                'metadata' => ['customer_id' => (string) $customer->id],
            ],
            ['idempotency_key' => 'ph_resolve_customer_'.$customer->id],
        );

        return (string) $stripeCustomer->id;
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
            'customer' => $this->resolveStripeCustomer($customer),
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

        $stripeCustomerId = $this->resolveStripeCustomer($customer);
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
     * Charge a saved card OFF-SESSION (Billing P2) and normalise the outcome.
     *
     * This is the ONLY place an off-session PaymentIntent is created. It mirrors
     * the on-session checkout params (customer, card, GBP, metadata) plus
     * off_session + confirm, and returns a flat, Stripe-free result so the
     * OffSessionCollector — and its tests — never touch the SDK directly:
     *
     *   - succeeded        → the charge cleared synchronously.
     *   - requires_action  → SCA / 3-D Secure needed; NOT a failure. The caller
     *                        emails a checkout link so the customer can finish.
     *   - failed           → declined or errored; failure_reason carries why.
     *
     * The deterministic idempotency key (per invoice + outstanding amount) means
     * a re-run returns the SAME PaymentIntent instead of charging twice.
     *
     * @param  array<string, string>  $metadata
     * @return array{status: string, payment_intent_id: ?string, failure_reason: ?string}
     */
    public function chargeOffSession(string $stripeCustomerId, string $paymentMethodId, int $amountPence, array $metadata, string $idempotencyKey): array
    {
        $this->configureStripe();

        try {
            $pi = PaymentIntent::create(
                [
                    'amount' => $amountPence,
                    'currency' => 'gbp',
                    'customer' => $stripeCustomerId,
                    'payment_method' => $paymentMethodId,
                    'off_session' => true,
                    'confirm' => true,
                    'payment_method_types' => ['card'],
                    'metadata' => $metadata,
                ],
                ['idempotency_key' => $idempotencyKey],
            );

            $status = match ((string) $pi->status) {
                'succeeded' => 'succeeded',
                'requires_action', 'requires_source_action' => 'requires_action',
                default => 'failed',
            };

            return [
                'status' => $status,
                'payment_intent_id' => (string) $pi->id,
                'failure_reason' => $status === 'succeeded' ? null : ('payment_intent status: '.$pi->status),
            ];
        } catch (CardException $e) {
            // A card that needs authentication throws here with code
            // 'authentication_required' and the PI attached to the error — that
            // is an SCA prompt, NOT a decline. Any other code is a real decline.
            $error = $e->getError();
            $pi = is_object($error) ? ($error->payment_intent ?? null) : null;
            $piId = is_object($pi) ? (isset($pi->id) ? (string) $pi->id : null) : null;
            $code = is_object($error) ? ($error->code ?? null) : null;

            if ($code === 'authentication_required') {
                return ['status' => 'requires_action', 'payment_intent_id' => $piId, 'failure_reason' => 'authentication_required'];
            }

            return ['status' => 'failed', 'payment_intent_id' => $piId, 'failure_reason' => $code ?? $e->getMessage()];
        } catch (ApiErrorException $e) {
            return ['status' => 'failed', 'payment_intent_id' => null, 'failure_reason' => $e->getMessage()];
        }
    }

    /**
     * Upsert a stripe ledger row keyed by PaymentIntent id (Billing P2). Used by
     * the webhook failure handler so a failed off-session PI is recorded exactly
     * once, converging with any `pending` row the command already wrote.
     *
     * @param  array<string, mixed>  $values
     */
    public function upsertStripePayment(string $paymentIntentId, array $values): Payment
    {
        return Payment::updateOrCreate(
            ['stripe_payment_intent_id' => $paymentIntentId],
            $values + ['rail' => 'stripe'],
        );
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

            // Ledger row for this settle. UPSERT keyed by the PI id (P2): the
            // off-session command may have already written a `pending` row for
            // this exact PaymentIntent, and the async webhook settles the same
            // PI — both must converge on ONE row rather than create duplicates.
            // On-session checkout (no prior row) simply creates one.
            $ledger = [
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'amount' => $invoice->total,
                'currency' => 'gbp',
                'rail' => 'stripe',
                'status' => 'succeeded',
                'attempted_at' => now(),
                'failure_reason' => null,
            ];
            if ($paymentIntentId !== '') {
                Payment::updateOrCreate(
                    ['stripe_payment_intent_id' => $paymentIntentId],
                    $ledger,
                );
            } else {
                Payment::create($ledger + ['stripe_payment_intent_id' => null, 'created_by' => null]);
            }

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
