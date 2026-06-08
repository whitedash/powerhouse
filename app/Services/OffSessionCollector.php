<?php

namespace App\Services;

use App\Mail\InvoiceSent;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Billing P2 — off-session invoice collection orchestrator.
 *
 * For each opted-in customer (auto_collect = true) with an active default card,
 * charges the OUTSTANDING balance of each collectible invoice off-session,
 * recording every attempt in the payments ledger and alerting on every failure.
 *
 * Money-safety guarantees:
 *   - auto_collect is the master gate — a customer without it is NEVER touched.
 *   - Every attempt is durably recorded (a `pending` ledger row is committed
 *     BEFORE the charge), so a crash mid-charge still leaves a trail the webhook
 *     can reconcile via metadata.
 *   - No double-charge: a deterministic Stripe idempotency key per (invoice,
 *     amount), a UNIQUE(stripe_payment_intent_id) ledger, and a skip on any
 *     invoice that already carries a stripe attempt.
 *   - No silent break: declines/errors are recorded + Sentry-alerted; SCA is
 *     recorded as requires_action and the customer is emailed a link.
 *
 * The Stripe network call deliberately runs OUTSIDE a DB transaction — DB
 * writes are wrapped in their own focused transactions, but holding one open
 * across the Stripe round-trip would risk charging without recording.
 */
class OffSessionCollector
{
    /** Stripe's minimum chargeable amount we hit earlier: £0.30 → 30 pence. */
    private const MIN_PENCE = 30;

    /** Invoice statuses that are collectible (exclude paid/void/partially_paid). */
    private const COLLECTIBLE = ['draft', 'sent', 'overdue'];

    public function __construct(private StripeService $stripe) {}

    /**
     * Run a collection sweep. Returns one result row per invoice considered, for
     * the command to print. Nothing is charged when $dryRun is true.
     *
     * @return array<int, array<string, mixed>>
     */
    public function run(bool $dryRun = false, ?int $onlyCustomerId = null, ?int $onlyInvoiceId = null): array
    {
        $customers = Customer::query()
            ->where('auto_collect', true)
            ->when($onlyCustomerId !== null, fn ($q) => $q->where('id', $onlyCustomerId))
            ->orderBy('id')
            ->get();

        $results = [];

        foreach ($customers as $customer) {
            $invoices = Invoice::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', self::COLLECTIBLE)
                ->when($onlyInvoiceId !== null, fn ($q) => $q->where('id', $onlyInvoiceId))
                ->orderBy('id')
                ->get();

            foreach ($invoices as $invoice) {
                $results[] = $this->collectInvoice($customer, $invoice, $dryRun);
            }
        }

        return $results;
    }

    /**
     * Attempt collection of a single invoice. Pure decision + side effects;
     * returns a result row describing what happened (or would happen).
     *
     * @return array<string, mixed>
     */
    public function collectInvoice(Customer $customer, Invoice $invoice, bool $dryRun = false): array
    {
        $outstanding = round((float) $invoice->total - (float) $invoice->amount_paid, 2);
        $pence = (int) round($outstanding * 100);

        // Defensive: the query excludes these, but never charge a settled invoice.
        if (in_array($invoice->status, ['paid', 'void'], true)) {
            return $this->result($customer, $invoice, $outstanding, 'skipped', 'already_settled');
        }

        // Stripe rejects sub-minimum charges; record + move on.
        if ($pence < self::MIN_PENCE) {
            return $this->result($customer, $invoice, $outstanding, 'skipped', 'below_minimum');
        }

        // Opted in but unchargeable — visible (logged + alerted), never silent.
        $pm = $this->defaultPaymentMethod($customer);
        if ($pm === null) {
            $this->alert('stripe.collect_no_default_pm', [
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
            ]);

            return $this->result($customer, $invoice, $outstanding, 'skipped', 'no_default_pm');
        }

        // Already has a stripe attempt (in-flight pending/requires_action, OR a
        // prior failed attempt). Skipping covers both "don't double-charge an
        // in-flight intent" and "no auto-retry in P2" (retry/dunning is P3).
        if ($this->hasStripeAttempt($invoice)) {
            return $this->result($customer, $invoice, $outstanding, 'skipped', 'already_attempted');
        }

        if ($dryRun) {
            return $this->result(
                $customer,
                $invoice,
                $outstanding,
                'would_charge',
                $invoice->status === 'draft' ? 'would_send_then_charge' : 'would_charge',
            );
        }

        // 1. Never charge an unsent draft — auto-send first (status, email, link).
        if ($invoice->status === 'draft') {
            $this->autoSend($invoice);
            $invoice->refresh();
        }

        // 2. Resolve the Stripe customer + default card.
        try {
            $stripeCustomerId = $this->stripe->resolveStripeCustomer($customer);
        } catch (\Throwable $e) {
            $this->alert('stripe.collect_customer_resolve_failed', [
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return $this->result($customer, $invoice, $outstanding, 'failed', 'customer_resolve_failed');
        }

        // 3. Record the attempt BEFORE charging (durable trail / reconciliation
        //    anchor via metadata.payment_id).
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => $outstanding,
            'currency' => 'gbp',
            'rail' => 'stripe',
            'status' => 'pending',
            'attempted_at' => now(),
            'created_by' => null,
        ]);

        // 4. Charge off-session.
        $outcome = $this->stripe->chargeOffSession(
            $stripeCustomerId,
            $pm->stripe_payment_method_id,
            $pence,
            [
                'invoice_id' => (string) $invoice->id,
                'customer_id' => (string) $customer->id,
                'payment_id' => (string) $payment->id,
            ],
            'ph_collect_inv_'.$invoice->id.'_'.$pence,
        );

        // Stamp the PI id on the attempt row immediately so the webhook (and
        // markInvoicePaid's upsert) reconcile onto THIS row, never a duplicate.
        if ($outcome['payment_intent_id'] !== null) {
            $payment->update(['stripe_payment_intent_id' => $outcome['payment_intent_id']]);
        }

        // 5. Act on the outcome.
        return match ($outcome['status']) {
            'succeeded' => $this->onSucceeded($customer, $invoice, $outstanding, $outcome['payment_intent_id'] ?? ''),
            'requires_action' => $this->onRequiresAction($customer, $invoice, $outstanding, $payment, $outcome['failure_reason']),
            default => $this->onFailed($customer, $invoice, $outstanding, $payment, $outcome['failure_reason']),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function onSucceeded(Customer $customer, Invoice $invoice, float $outstanding, string $paymentIntentId): array
    {
        // markInvoicePaid upserts the ledger row (keyed by PI id) to succeeded
        // and is idempotent — so the inline path and the async webhook converge.
        $this->stripe->markInvoicePaid($invoice, '', $paymentIntentId);

        Log::info('stripe.collect_succeeded', [
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => $outstanding,
            'payment_intent' => $paymentIntentId,
        ]);

        return $this->result($customer, $invoice, $outstanding, 'succeeded', 'charged', $paymentIntentId);
    }

    /**
     * @return array<string, mixed>
     */
    private function onRequiresAction(Customer $customer, Invoice $invoice, float $outstanding, Payment $payment, ?string $reason): array
    {
        $payment->update([
            'status' => 'requires_action',
            'failure_reason' => $reason ?? 'authentication_required',
        ]);

        // Don't fail it: email a checkout link so the customer can complete 3-D
        // Secure on-session. Best-effort — a mail/link hiccup must not break the
        // sweep; the requires_action row keeps it visible regardless.
        try {
            $this->emailPaymentLink($invoice);
        } catch (\Throwable $e) {
            Log::warning('stripe.collect_sca_link_failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logActivity($invoice, 'invoice.collect_requires_action', [
            'number' => $invoice->number,
            'amount' => $outstanding,
            'payment_intent' => $payment->stripe_payment_intent_id,
        ]);

        Log::warning('stripe.collect_requires_action', [
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'payment_intent' => $payment->stripe_payment_intent_id,
        ]);

        return $this->result($customer, $invoice, $outstanding, 'requires_action', 'sca_link_emailed', $payment->stripe_payment_intent_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function onFailed(Customer $customer, Invoice $invoice, float $outstanding, Payment $payment, ?string $reason): array
    {
        $payment->update([
            'status' => 'failed',
            'failure_reason' => $reason ?? 'charge_failed',
        ]);

        $this->logActivity($invoice, 'invoice.collect_failed', [
            'number' => $invoice->number,
            'amount' => $outstanding,
            'reason' => $reason,
            'payment_intent' => $payment->stripe_payment_intent_id,
        ]);

        // Record + alert (no retry in P2).
        $this->alert('stripe.collect_failed', [
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => $outstanding,
            'reason' => $reason,
            'payment_intent' => $payment->stripe_payment_intent_id,
        ]);

        return $this->result($customer, $invoice, $outstanding, 'failed', $reason ?? 'charge_failed', $payment->stripe_payment_intent_id);
    }

    /**
     * The customer's active default card, or null. Active is the master gate —
     * a removed card is never charged.
     */
    private function defaultPaymentMethod(Customer $customer): ?PaymentMethod
    {
        return PaymentMethod::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->where('is_default', true)
            ->first();
    }

    /**
     * Whether this invoice already carries a stripe attempt that should stop a
     * fresh charge: in-flight (pending/requires_action) or a prior failure
     * (no auto-retry in P2).
     */
    private function hasStripeAttempt(Invoice $invoice): bool
    {
        return Payment::where('invoice_id', $invoice->id)
            ->where('rail', 'stripe')
            ->whereIn('status', ['pending', 'requires_action', 'failed'])
            ->exists();
    }

    /**
     * Auto-send a draft before charging — mirrors InvoiceController::sendInvoice
     * (status → sent, sent_at, system activity_log, best-effort hosted link,
     * InvoiceSent email) without the request/redirect coupling.
     */
    private function autoSend(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $invoice->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            $this->logActivity($invoice, 'invoice.sent', [
                'number' => $invoice->number,
                'auto' => true,
                'trigger' => 'collect-due',
            ]);
        });

        // Best-effort hosted link so the email's "Pay Now" works — never blocks.
        if (! $invoice->stripe_payment_link && config('services.stripe.secret')) {
            try {
                $session = $this->stripe->createCheckoutSession($invoice);
                $invoice->update([
                    'stripe_payment_link' => $session->url,
                    'stripe_checkout_session_id' => $session->id,
                ]);
            } catch (\Throwable $e) {
                Log::warning('stripe.collect_autosend_link_failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $recipient = $invoice->customer?->primaryContact?->email;
        if ($recipient !== null) {
            Mail::to($recipient)->send(new InvoiceSent($invoice));
        }
    }

    /**
     * Refresh + email a hosted checkout link so a customer can complete SCA.
     */
    private function emailPaymentLink(Invoice $invoice): void
    {
        $session = $this->stripe->createCheckoutSession($invoice);
        $invoice->update([
            'stripe_payment_link' => $session->url,
            'stripe_checkout_session_id' => $session->id,
        ]);

        $recipient = $invoice->customer?->primaryContact?->email;
        if ($recipient !== null) {
            Mail::to($recipient)->send(new InvoiceSent($invoice->fresh() ?? $invoice));
        }
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function logActivity(Invoice $invoice, string $action, array $after): void
    {
        ActivityLog::create([
            'user_id' => null,
            'user_role' => 'system',
            'action' => $action,
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'after' => $after,
            'ip_address' => null,
            'user_agent' => 'artisan:invoices:collect-due',
        ]);
    }

    /**
     * Observable failure: log a warning AND raise it to Sentry.
     *
     * @param  array<string, mixed>  $context
     */
    private function alert(string $key, array $context): void
    {
        Log::warning($key, $context);

        if (function_exists('Sentry\captureMessage')) {
            \Sentry\captureMessage($key.' '.json_encode($context));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Customer $customer, Invoice $invoice, float $outstanding, string $status, string $reason, ?string $paymentIntentId = null): array
    {
        return [
            'customer_id' => $customer->id,
            'customer' => $customer->name,
            'invoice_id' => $invoice->id,
            'invoice' => $invoice->number,
            'amount' => $outstanding,
            'status' => $status,
            'reason' => $reason,
            'payment_intent' => $paymentIntentId,
        ];
    }
}
