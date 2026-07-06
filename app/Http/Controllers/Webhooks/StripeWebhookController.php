<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Mail\PlanPurchaseReceipt;
use App\Mail\ReinstatementNotice;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Services\PlanPurchaseService;
use App\Services\StripeService;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Event;
use Stripe\StripeObject;

/**
 * Stripe webhook receiver — POST /webhooks/stripe.
 *
 * Signature verification happens in VerifyStripeWebhook middleware BEFORE
 * this controller runs (CLAUDE.md webhook rule). The middleware stashes the
 * trusted Stripe\Event on the request as "stripeEvent".
 *
 * Idempotency: every event is deduped by its Stripe event id via
 * WebhookIdempotencyService — Stripe retries deliveries, and a duplicate
 * checkout.session.completed must not double-process. We always return 200
 * to a duplicate so Stripe stops retrying.
 */
class StripeWebhookController extends Controller
{
    public function receive(Request $request, StripeService $stripe, WebhookIdempotencyService $idempotency): JsonResponse
    {
        /** @var Event|null $event */
        $event = $request->attributes->get('stripeEvent');
        if (! $event instanceof Event) {
            // Belt-and-braces: middleware should have aborted already.
            abort(401);
        }

        $source = 'stripe';

        if ($idempotency->hasBeenProcessed($source, $event->id)) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object, $stripe),
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($event->data->object, $stripe),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object, $stripe),
            default => null,
        };

        // Record the event only after handling succeeded — an exception
        // above means we never mark it processed, so Stripe's retry gets a
        // fresh attempt rather than being silently swallowed as a dupe.
        $processed = $idempotency->record($source, $event->id, $event->type, ['id' => $event->id]);
        $idempotency->markProcessed($processed);

        return response()->json(['received' => true]);
    }

    private function handleCheckoutCompleted(StripeObject $session, StripeService $stripe): void
    {
        $invoiceId = $session->metadata->invoice_id ?? $session->client_reference_id ?? null;

        // Plans-widget purchases (PLANS-WIDGET-DESIGN.md §4) carry a
        // plan_price_id and NO invoice — the session was created by the
        // public checkout endpoint before any records existed. Everything
        // below this branch is the pre-existing invoice settlement path,
        // untouched.
        $planPriceId = $session->metadata->plan_price_id ?? null;
        if ($planPriceId !== null && $invoiceId === null) {
            $this->handlePlanPurchase($session);

            return;
        }

        if (! $invoiceId) {
            return;
        }

        $invoice = Invoice::find((int) $invoiceId);
        if (! $invoice || $invoice->status === 'paid') {
            return;
        }

        $reinstated = $stripe->markInvoicePaid(
            $invoice,
            (string) $session->id,
            (string) ($session->payment_intent ?? ''),
        );

        // Vault the card used for this payment (setup_future_usage) so it's
        // reusable in P2. Guarded: a vaulting failure must NEVER undo the
        // payment/reinstatement above. NOT a charge — P1 stores only.
        try {
            $stripe->vaultCardFromSession($invoice->fresh() ?? $invoice, (string) $session->id);
        } catch (\Throwable $e) {
            Log::warning('stripe.vault_failed', [
                'invoice_id' => $invoice->id,
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('stripe.checkout_completed', [
            'invoice_id' => $invoice->id,
            'session_id' => $session->id,
            'reinstated_products' => count($reinstated),
        ]);

        $this->notifyReinstated($reinstated);
    }

    /**
     * Provision + settle a Plans-widget purchase. The heavy lifting
     * (Company/Contact/Person via the system-actor funnel, CustomerProduct,
     * invoice + line, markInvoicePaid) lives in PlanPurchaseService; this
     * method only unpacks the session and sends the receipt after the
     * transaction has committed — mirroring how notifyReinstated() mails
     * outside the settle transaction so a mail hiccup can't roll back the
     * payment.
     */
    private function handlePlanPurchase(StripeObject $session): void
    {
        $result = app(PlanPurchaseService::class)->settle(
            (string) $session->id,
            (string) ($session->payment_intent ?? ''),
            (int) ($session->metadata->plan_price_id ?? 0),
            (string) ($session->metadata->purchaser_name ?? ''),
            (string) ($session->metadata->purchaser_email ?? ''),
            // amount_total isn't declared on the base StripeObject; read it
            // via ArrayAccess so static analysis stays happy.
            isset($session['amount_total']) ? (int) $session['amount_total'] : null,
        );

        if ($result === null) {
            return;
        }

        Log::info('stripe.plan_purchase_completed', [
            'session_id' => $session->id,
            'invoice_id' => $result->invoice->id,
            'customer_id' => $result->company->id,
            'customer_product_id' => $result->customerProduct?->id,
            'status' => $result->customerProduct?->status,
        ]);

        // Receipt is purchase-specific and withheld while the subscription
        // sits pending manual review (the confirm action sends it later).
        if ($result->receiptDue && $result->contactEmail !== null && $result->customerProduct !== null) {
            Mail::to($result->contactEmail)
                ->send(new PlanPurchaseReceipt($result->invoice, $result->customerProduct));
        }
    }

    private function handlePaymentSucceeded(StripeObject $intent, StripeService $stripe): void
    {
        // Reconciles a payment that confirmed asynchronously. Two cases:
        //  - on-session: checkout.session.completed already stamped the PI on the
        //    invoice, so match by column.
        //  - off-session (P2): the collect-due command created the PI; the
        //    invoice column isn't set yet, so fall back to metadata.invoice_id.
        $invoice = Invoice::where('stripe_payment_intent_id', $intent->id)->first();
        if (! $invoice) {
            $invoiceId = $intent->metadata->invoice_id ?? null;
            $invoice = $invoiceId ? Invoice::find((int) $invoiceId) : null;
        }
        if (! $invoice || $invoice->status === 'paid') {
            return;
        }

        // markInvoicePaid is idempotent and upserts the ledger row by PI id, so
        // the command's inline success and this async event converge on ONE row
        // and ONE paid mark — never a double-charge or duplicate record.
        $reinstated = $stripe->markInvoicePaid(
            $invoice,
            (string) ($invoice->stripe_checkout_session_id ?? ''),
            (string) $intent->id,
        );

        $this->notifyReinstated($reinstated);
    }

    private function handlePaymentFailed(StripeObject $intent, StripeService $stripe): void
    {
        // Attribute the failure via PI metadata (off-session) so it's recorded
        // even when no invoice column was stamped.
        $invoiceId = $intent->metadata->invoice_id ?? null;
        $customerId = $intent->metadata->customer_id ?? null;
        $lastError = $intent->last_payment_error ?? null;
        $reason = is_object($lastError) ? ($lastError->message ?? 'payment_failed') : 'payment_failed';

        // Record the failure in the ledger (P2) — upsert keyed by PI id so it
        // converges with any `pending` row the command already wrote, never a
        // duplicate. The webhook is the source of truth for the outcome.
        if ($invoiceId !== null) {
            // amount is in pence on the PI; default 0 if absent. updateOrCreate
            // updates the command's `pending` row in place (amount already set)
            // or inserts a complete row for a pure-webhook failure.
            $amountPence = (int) ($intent['amount'] ?? 0);
            $stripe->upsertStripePayment((string) $intent->id, [
                'invoice_id' => (int) $invoiceId,
                'customer_id' => $customerId !== null ? (int) $customerId : null,
                'amount' => round($amountPence / 100, 2),
                'currency' => 'gbp',
                'status' => 'failed',
                'failure_reason' => $reason,
                'attempted_at' => now(),
            ]);
        }

        Log::warning('stripe.payment_failed', [
            'payment_intent' => $intent->id,
            'invoice_id' => $invoiceId,
            'reason' => $reason,
            // amount isn't declared on the base StripeObject; read it via
            // ArrayAccess so static analysis stays happy.
            'amount' => $intent['amount'] ?? null,
        ]);

        if (function_exists('Sentry\captureMessage')) {
            \Sentry\captureMessage('stripe.payment_failed pi='.$intent->id.' invoice='.($invoiceId ?? 'unknown'));
        }
    }

    /**
     * @param  array<int, CustomerProduct>  $reinstated
     */
    private function notifyReinstated(array $reinstated): void
    {
        // Sent outside the markInvoicePaid transaction (it has committed by
        // now) so a mail hiccup can't roll back the payment or reinstatement.
        foreach ($reinstated as $cp) {
            $email = $cp->customer?->primaryContact?->email;
            if ($email !== null) {
                Mail::to($email)->send(new ReinstatementNotice($cp, $cp->customer));
            }
        }
    }
}
