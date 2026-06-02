<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Mail\ReinstatementNotice;
use App\Models\CustomerProduct;
use App\Models\Invoice;
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
            'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object),
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

        Log::info('stripe.checkout_completed', [
            'invoice_id' => $invoice->id,
            'session_id' => $session->id,
            'reinstated_products' => count($reinstated),
        ]);

        $this->notifyReinstated($reinstated);
    }

    private function handlePaymentSucceeded(StripeObject $intent, StripeService $stripe): void
    {
        // Reconciles a payment that confirmed asynchronously (e.g. delayed
        // capture) after checkout.session.completed already matched it by
        // session id. Find the invoice via the intent we stamped earlier.
        $invoice = Invoice::where('stripe_payment_intent_id', $intent->id)->first();
        if (! $invoice || $invoice->status === 'paid') {
            return;
        }

        $reinstated = $stripe->markInvoicePaid(
            $invoice,
            (string) ($invoice->stripe_checkout_session_id ?? ''),
            (string) $intent->id,
        );

        $this->notifyReinstated($reinstated);
    }

    private function handlePaymentFailed(StripeObject $intent): void
    {
        Log::warning('stripe.payment_failed', [
            'payment_intent' => $intent->id,
            // amount isn't declared on the base StripeObject; read it via
            // ArrayAccess so static analysis stays happy.
            'amount' => $intent['amount'] ?? null,
        ]);
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
