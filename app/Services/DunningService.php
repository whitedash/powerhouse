<?php

namespace App\Services;

use App\Mail\FailedPaymentNotice;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Billing P3 — dunning for failed off-session collections.
 *
 * P2's collect-due makes ONE off-session attempt and, on decline, records a
 * `failed` ledger row (attempt 1). P3 retries on a cadence, emails the customer
 * on every failed attempt, and — when retries are exhausted — escalates into
 * the EXISTING overdue→suspend backbone (ProcessSuspensions) rather than
 * suspending in parallel. A successful retry settles + auto-reinstates.
 *
 * Money-safety (same bar as P2):
 *   - State derives from the payments ledger — no new schema. Attempt count =
 *     the invoice's failed/requires_action stripe rows; cadence is anchored to
 *     the FIRST such row.
 *   - PER-ATTEMPT Stripe idempotency key (ph_dunning_inv_{id}_{pence}_a{n}) so a
 *     retry actually charges, while a same-day re-run with the same attempt
 *     number is Stripe-deduped (never a double charge).
 *   - Every attempt is recorded; every failure is Sentry-alerted; nothing
 *     breaks silently.
 *   - Reuses chargeOffSession + markInvoicePaid (which auto-reinstates) — no
 *     duplicated money logic.
 */
class DunningService
{
    /** Hard cap: attempt 1 (collect-due) + 2 retries. No charge past this. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Days AFTER the first failed attempt to make the next retry, keyed by the
     * current failed-attempt count. After 1 failure → retry at +3d (attempt 2);
     * after 2 → +6d (attempt 3). Deterministic (anchored to the first failure),
     * not rolling from the previous attempt.
     *
     * @var array<int, int>
     */
    private const RETRY_DAYS_AFTER_FIRST = [1 => 3, 2 => 6];

    /** Stripe's minimum chargeable amount: £0.30. */
    private const MIN_PENCE = 30;

    public function __construct(private StripeService $stripe) {}

    /**
     * Daily dunning sweep. Returns one result row per invoice considered.
     * Nothing is charged/emailed/recorded when $dryRun is true.
     *
     * @return array<int, array<string, mixed>>
     */
    public function run(bool $dryRun = false, ?int $onlyCustomerId = null, ?int $onlyInvoiceId = null): array
    {
        $invoices = Invoice::query()
            ->whereIn('status', ['sent', 'overdue'])
            ->whereHas('customer', fn ($q) => $q->where('auto_collect', true))
            ->whereHas('payments', fn ($q) => $q->where('rail', 'stripe')->whereIn('status', ['failed', 'requires_action']))
            ->when($onlyCustomerId !== null, fn ($q) => $q->where('customer_id', $onlyCustomerId))
            ->when($onlyInvoiceId !== null, fn ($q) => $q->where('id', $onlyInvoiceId))
            ->with('customer.primaryContact')
            ->orderBy('id')
            ->get();

        $results = [];
        foreach ($invoices as $invoice) {
            if ($invoice->customer === null) {
                continue;
            }
            $results[] = $this->processInvoice($invoice, $invoice->customer, $dryRun);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function processInvoice(Invoice $invoice, Customer $customer, bool $dryRun = false): array
    {
        $outstanding = round((float) $invoice->total - (float) $invoice->amount_paid, 2);

        // A settled invoice is never in dunning (selection excludes paid/void).
        if (in_array($invoice->status, ['paid', 'void'], true)) {
            return $this->result($invoice, $customer, 0, 'skipped', 'already_settled');
        }

        $attempts = $this->dunningAttempts($invoice);
        $count = $attempts->count();

        // An in-flight (pending) or already-succeeded stripe row means a settle
        // is converging — leave it to the webhook/markInvoicePaid, never charge.
        $latestStripe = Payment::where('invoice_id', $invoice->id)
            ->where('rail', 'stripe')
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->first();
        if ($latestStripe !== null && in_array($latestStripe->status, ['pending', 'succeeded'], true)) {
            return $this->result($invoice, $customer, $count, 'skipped', 'in_flight');
        }

        // Exhausted — the final attempt already fired its email + escalation.
        if ($count >= self::MAX_ATTEMPTS) {
            return $this->result($invoice, $customer, $count, 'skipped', 'exhausted');
        }

        // Not yet due for the next retry (cadence anchored to the first failure).
        $nextDue = $this->nextRetryDue($attempts->first(), $count);
        if ($nextDue === null || $nextDue->isFuture()) {
            return $this->result($invoice, $customer, $count, 'skipped', 'not_due');
        }

        $attemptNo = $count + 1;            // the attempt we are about to make
        $isFinal = $attemptNo >= self::MAX_ATTEMPTS;

        if ($dryRun) {
            $sca = $latestStripe !== null && $latestStripe->status === 'requires_action';
            $action = $sca ? 'would_relink_sca' : ($isFinal ? 'would_final_retry' : 'would_retry');

            return $this->result($invoice, $customer, $attemptNo, 'would_'.($sca ? 'relink' : 'retry'), $action);
        }

        // SCA cannot be cleared off-session — re-send the hosted link instead of
        // a guaranteed-failing retry (the chosen requires_action path).
        if ($latestStripe !== null && $latestStripe->status === 'requires_action') {
            return $this->relinkSca($invoice, $customer, $attemptNo, $isFinal);
        }

        return $this->retryCharge($invoice, $customer, $outstanding, $attemptNo, $isFinal);
    }

    /**
     * Off-session retry with a PER-ATTEMPT idempotency key.
     *
     * @return array<string, mixed>
     */
    private function retryCharge(Invoice $invoice, Customer $customer, float $outstanding, int $attemptNo, bool $isFinal): array
    {
        $pence = (int) round($outstanding * 100);
        if ($pence < self::MIN_PENCE) {
            return $this->result($invoice, $customer, $attemptNo, 'skipped', 'below_minimum');
        }

        $pm = $this->defaultPaymentMethod($customer);
        if ($pm === null) {
            // Opted in but unchargeable (card removed mid-dunning). Record a
            // failed marker so the cadence still advances + eventually escalates,
            // alert, and tell the customer. Never loops silently.
            $this->recordFailedMarker($invoice, $customer, $outstanding, 'no_default_pm');
            $this->alert('dunning.no_default_pm', ['invoice_id' => $invoice->id, 'customer_id' => $customer->id]);
            $this->notifyFailure($invoice, $attemptNo, self::MAX_ATTEMPTS);
            if ($isFinal) {
                $this->escalateToSuspension($invoice);
            }

            return $this->result($invoice, $customer, $attemptNo, 'failed', 'no_default_pm');
        }

        try {
            $stripeCustomerId = $this->stripe->resolveStripeCustomer($customer);
        } catch (\Throwable $e) {
            $this->recordFailedMarker($invoice, $customer, $outstanding, 'customer_resolve_failed');
            $this->alert('dunning.customer_resolve_failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            $this->notifyFailure($invoice, $attemptNo, self::MAX_ATTEMPTS);
            if ($isFinal) {
                $this->escalateToSuspension($invoice);
            }

            return $this->result($invoice, $customer, $attemptNo, 'failed', 'customer_resolve_failed');
        }

        // Durable pending row BEFORE the charge (reconciliation anchor).
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

        $outcome = $this->stripe->chargeOffSession(
            $stripeCustomerId,
            $pm->stripe_payment_method_id,
            $pence,
            [
                'invoice_id' => (string) $invoice->id,
                'customer_id' => (string) $customer->id,
                'payment_id' => (string) $payment->id,
            ],
            // PER-ATTEMPT key — differs each retry (fresh charge), stable within
            // the same attempt (same-day re-run is Stripe-deduped).
            'ph_dunning_inv_'.$invoice->id.'_'.$pence.'_a'.$attemptNo,
        );

        if ($outcome['payment_intent_id'] !== null) {
            $payment->update(['stripe_payment_intent_id' => $outcome['payment_intent_id']]);
        }

        if ($outcome['status'] === 'succeeded') {
            // Idempotent settle (upserts the ledger row by PI id) + auto-reinstate.
            $this->stripe->markInvoicePaid($invoice, '', $outcome['payment_intent_id'] ?? '');
            $this->logActivity($invoice, 'invoice.dunning_recovered', [
                'number' => $invoice->number, 'attempt' => $attemptNo, 'amount' => $outstanding,
                'payment_intent' => $outcome['payment_intent_id'],
            ]);
            Log::info('dunning.recovered', ['invoice_id' => $invoice->id, 'attempt' => $attemptNo]);

            return $this->result($invoice, $customer, $attemptNo, 'recovered', 'charged', $outcome['payment_intent_id']);
        }

        if ($outcome['status'] === 'requires_action') {
            $payment->update(['status' => 'requires_action', 'failure_reason' => $outcome['failure_reason'] ?? 'authentication_required']);
            $this->relinkOnly($invoice);
            $this->notifyFailure($invoice, $attemptNo, self::MAX_ATTEMPTS);
            $this->logActivity($invoice, 'invoice.dunning_requires_action', ['number' => $invoice->number, 'attempt' => $attemptNo]);
            if ($isFinal) {
                $this->escalateToSuspension($invoice);
            }

            return $this->result($invoice, $customer, $attemptNo, 'requires_action', 'sca_link_emailed', $payment->stripe_payment_intent_id);
        }

        // Declined / errored.
        $payment->update(['status' => 'failed', 'failure_reason' => $outcome['failure_reason'] ?? 'charge_failed']);
        $this->afterFailedAttempt($invoice, $customer, $attemptNo, $isFinal, $outcome['failure_reason']);

        return $this->result($invoice, $customer, $attemptNo, $isFinal ? 'final_failed' : 'retry_failed', $outcome['failure_reason'] ?? 'charge_failed', $payment->stripe_payment_intent_id);
    }

    /**
     * SCA path — re-send the hosted payment link (no off-session charge) and
     * record a requires_action marker so the cadence + cap advance.
     *
     * @return array<string, mixed>
     */
    private function relinkSca(Invoice $invoice, Customer $customer, int $attemptNo, bool $isFinal): array
    {
        $this->relinkOnly($invoice);

        Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => round((float) $invoice->total - (float) $invoice->amount_paid, 2),
            'currency' => 'gbp',
            'rail' => 'stripe',
            'status' => 'requires_action',
            'failure_reason' => 'sca_link_resent',
            'attempted_at' => now(),
            'created_by' => null,
        ]);

        $this->notifyFailure($invoice, $attemptNo, self::MAX_ATTEMPTS);
        $this->logActivity($invoice, 'invoice.dunning_sca_relink', ['number' => $invoice->number, 'attempt' => $attemptNo]);
        if ($isFinal) {
            $this->escalateToSuspension($invoice);
        }

        return $this->result($invoice, $customer, $attemptNo, 'requires_action', 'sca_link_emailed');
    }

    /**
     * Shared post-failure handling: log, email (final-toned if exhausting), and
     * escalate to the suspend backbone on the final attempt. Sentry alert too.
     */
    private function afterFailedAttempt(Invoice $invoice, Customer $customer, int $attemptNo, bool $isFinal, ?string $reason): void
    {
        $this->logActivity($invoice, 'invoice.dunning_failed', [
            'number' => $invoice->number, 'attempt' => $attemptNo, 'final' => $isFinal, 'reason' => $reason,
        ]);
        $this->alert('dunning.attempt_failed', [
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'attempt' => $attemptNo, 'reason' => $reason,
        ]);
        $this->notifyFailure($invoice, $attemptNo, self::MAX_ATTEMPTS);

        if ($isFinal) {
            $this->escalateToSuspension($invoice);
        }
    }

    /**
     * Send the dunning email for a failed attempt. Public so collect-due's
     * initial failure (attempt 1) sends the same email — P3 wires it in there.
     * Best-effort: a mail hiccup must never break the sweep.
     */
    public function notifyFailure(Invoice $invoice, int $attempt, int $maxAttempts): void
    {
        $recipient = $invoice->customer?->primaryContact?->email;
        if ($recipient === null) {
            return;
        }

        try {
            Mail::to($recipient)->send(new FailedPaymentNotice(
                $invoice->fresh() ?? $invoice,
                $attempt,
                $maxAttempts,
                $attempt >= $maxAttempts,
            ));
        } catch (\Throwable $e) {
            Log::warning('dunning.email_failed', ['invoice_id' => $invoice->id, 'attempt' => $attempt, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Make the invoice eligible for the EXISTING overdue→suspend backbone by
     * writing the final-notice marker ProcessSuspensions reads. We do NOT
     * suspend here — ProcessSuspensions still owns the overdue-threshold + grace
     * rules (no parallel suspend).
     */
    private function escalateToSuspension(Invoice $invoice): void
    {
        ActivityLog::create([
            'user_id' => null,
            'user_role' => 'system',
            // ProcessSuspensions gates on action=invoice.reminder_sent +
            // after->tier=final_notice; this is the deliberate bridge.
            'action' => 'invoice.reminder_sent',
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'after' => [
                'tier' => 'final_notice',
                'source' => 'dunning',
                'method' => 'dunning_exhausted',
            ],
            'ip_address' => null,
            'user_agent' => 'billing:process-dunning',
        ]);

        Log::warning('dunning.exhausted_escalated', ['invoice_id' => $invoice->id]);
    }

    /**
     * Refresh the hosted checkout link on the invoice (best-effort) so the
     * dunning email's "Pay now" points somewhere live.
     */
    private function relinkOnly(Invoice $invoice): void
    {
        if (! config('services.stripe.secret')) {
            return;
        }

        try {
            $session = $this->stripe->createCheckoutSession($invoice);
            $invoice->update([
                'stripe_payment_link' => $session->url,
                'stripe_checkout_session_id' => $session->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('dunning.relink_failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
        }
    }

    private function recordFailedMarker(Invoice $invoice, Customer $customer, float $outstanding, string $reason): void
    {
        Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => $outstanding,
            'currency' => 'gbp',
            'rail' => 'stripe',
            'status' => 'failed',
            'failure_reason' => $reason,
            'attempted_at' => now(),
            'created_by' => null,
        ]);
    }

    /**
     * Failed + requires_action stripe attempts for an invoice, oldest first.
     * These ARE the dunning attempts; their count + the first one's timestamp
     * drive the cadence.
     *
     * @return Collection<int, Payment>
     */
    private function dunningAttempts(Invoice $invoice): Collection
    {
        return Payment::where('invoice_id', $invoice->id)
            ->where('rail', 'stripe')
            ->whereIn('status', ['failed', 'requires_action'])
            ->orderBy('attempted_at')
            ->orderBy('id')
            ->get();
    }

    private function nextRetryDue(?Payment $firstAttempt, int $count): ?Carbon
    {
        if ($firstAttempt === null || ! isset(self::RETRY_DAYS_AFTER_FIRST[$count])) {
            return null;
        }

        $anchor = $firstAttempt->attempted_at ?? $firstAttempt->created_at;

        return $anchor?->copy()->addDays(self::RETRY_DAYS_AFTER_FIRST[$count]);
    }

    private function defaultPaymentMethod(Customer $customer): ?PaymentMethod
    {
        return PaymentMethod::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->where('is_default', true)
            ->first();
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
            'user_agent' => 'billing:process-dunning',
        ]);
    }

    /**
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
    private function result(Invoice $invoice, Customer $customer, int $attempt, string $status, string $reason, ?string $paymentIntentId = null): array
    {
        return [
            'invoice_id' => $invoice->id,
            'invoice' => $invoice->number,
            'customer' => $customer->name,
            'attempt' => $attempt,
            'max_attempts' => self::MAX_ATTEMPTS,
            'status' => $status,
            'reason' => $reason,
            'payment_intent' => $paymentIntentId,
        ];
    }
}
