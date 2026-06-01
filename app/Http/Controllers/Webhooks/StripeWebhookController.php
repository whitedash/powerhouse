<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Mail\PaymentReceived;
use App\Mail\ReinstatementNotice;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\WebhookDispatcher;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Stripe\Event;

/**
 * Inbound Stripe webhook. The signature is verified upstream by the
 * VerifyStripeWebhook middleware (fail-closed), so by the time we're here
 * the payload is trusted. We still:
 *   - dedupe on event id via WebhookIdempotencyService (Stripe retries),
 *   - only act on the two event types we registered for.
 * The route is CSRF-exempt via the webhooks/* rule in bootstrap/app.php.
 */
class StripeWebhookController extends Controller
{
    private const SOURCE = 'stripe';

    public function __construct(
        private readonly WebhookIdempotencyService $idempotency,
        private readonly WebhookDispatcher $dispatcher,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (! is_array($payload) || empty($payload['id']) || empty($payload['type'])) {
            return response()->json(['error' => 'Malformed payload'], 400);
        }

        $event = Event::constructFrom($payload);

        // Stripe retries until it gets a 2xx — short-circuit replays.
        if ($this->idempotency->hasBeenProcessed(self::SOURCE, $event->id)) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $record = $this->idempotency->record(self::SOURCE, $event->id, $event->type, $payload);

        match ($event->type) {
            'checkout.session.completed' => $this->handlePaymentSuccess($event->data->object),
            'checkout.session.expired' => $this->handleSessionExpired($event->data->object),
            default => null,
        };

        $this->idempotency->markProcessed($record);

        return response()->json(['received' => true]);
    }

    /**
     * A hosted-checkout session was paid. Settle the referenced invoice(s),
     * email a receipt, and auto-reinstate any products suspended for
     * non-payment once the customer has no outstanding balance left.
     */
    private function handlePaymentSuccess(object $session): void
    {
        $invoiceIds = $this->invoiceIdsFromMetadata($session);
        if ($invoiceIds === []) {
            return;
        }

        $invoices = Invoice::whereIn('id', $invoiceIds)->get();
        if ($invoices->isEmpty()) {
            return;
        }

        $customer = Customer::with('primaryContact')->find($invoices->first()->customer_id);
        if ($customer === null) {
            return;
        }

        $paymentIntent = is_string($session->payment_intent ?? null) ? $session->payment_intent : null;
        $autoReinstate = (bool) Setting::getValue('billing.auto_reinstate', true);

        /** @var list<CustomerProduct> $reinstated */
        $reinstated = [];

        DB::transaction(function () use ($invoices, $customer, $paymentIntent, $autoReinstate, &$reinstated): void {
            foreach ($invoices as $invoice) {
                if (in_array($invoice->status, ['paid', 'void'], true)) {
                    continue;
                }

                $invoice->update([
                    'status' => 'paid',
                    'amount_paid' => $invoice->total,
                    'paid_at' => now(),
                    'paid_via' => 'stripe',
                    'stripe_payment_intent_id' => $paymentIntent,
                ]);

                $this->log('invoice.paid_via_stripe', $invoice->id, [
                    'number' => $invoice->number,
                    'total' => $invoice->total,
                    'payment_intent' => $paymentIntent,
                ]);
            }

            if (! $autoReinstate) {
                return;
            }

            // Reinstate only once the customer has nothing left owing.
            $stillOwing = Invoice::where('customer_id', $customer->id)
                ->whereIn('status', ['sent', 'overdue', 'partially_paid'])
                ->exists();
            if ($stillOwing) {
                return;
            }

            $suspended = CustomerProduct::where('customer_id', $customer->id)
                ->where('status', 'suspended')
                ->where('suspension_reason', 'non_payment')
                ->get();

            foreach ($suspended as $cp) {
                $cp->update([
                    'status' => 'active',
                    'reinstated_at' => now(),
                    'reinstated_by' => null, // null = auto-reinstated by the system
                    'reinstatement_reason' => 'Auto-reinstated after Stripe payment',
                    'suspension_reason' => null,
                    'suspended_at' => null,
                    'suspended_by' => null,
                ]);

                $this->log('customer_product.reinstated', $customer->id, [
                    'customer_product_id' => $cp->id,
                    'auto' => true,
                    'trigger' => 'stripe_payment',
                ]);

                $reinstated[] = $cp;
            }
        });

        // Side effects after commit — never fire emails/webhooks for work
        // that rolled back.
        $contactEmail = $customer->primaryContact?->email;
        if ($contactEmail !== null) {
            Mail::to($contactEmail)->send(new PaymentReceived($invoices->first()));
        }

        foreach ($reinstated as $cp) {
            $this->dispatcher->dispatchReinstatement($cp);
            if ($contactEmail !== null) {
                Mail::to($contactEmail)->send(new ReinstatementNotice($cp, $customer));
            }
        }
    }

    /**
     * Checkout link expired before payment — clear the stale link off the
     * invoice so the UI stops offering a dead URL.
     */
    private function handleSessionExpired(object $session): void
    {
        $sessionId = is_string($session->id ?? null) ? $session->id : null;
        if ($sessionId === null) {
            return;
        }

        Invoice::where('stripe_checkout_session_id', $sessionId)
            ->whereIn('status', ['sent', 'overdue', 'partially_paid'])
            ->update([
                'stripe_payment_link' => null,
                'stripe_checkout_session_id' => null,
            ]);
    }

    /**
     * @return array<int, int>
     */
    private function invoiceIdsFromMetadata(object $session): array
    {
        $metadata = $session->metadata ?? null;
        if ($metadata === null) {
            return [];
        }

        // Single-invoice link sets invoice_id; the "pay outstanding" flow
        // sets a comma-separated invoice_ids.
        $single = $metadata->invoice_id ?? null;
        $multi = $metadata->invoice_ids ?? null;

        $ids = [];
        if (is_string($single) && $single !== '') {
            $ids[] = (int) $single;
        }
        if (is_string($multi) && $multi !== '') {
            foreach (explode(',', $multi) as $id) {
                $ids[] = (int) trim($id);
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function log(string $action, int $entityId, array $after): void
    {
        ActivityLog::create([
            'user_id' => null,
            'user_role' => 'system',
            'action' => $action,
            'entity_type' => 'invoice',
            'entity_id' => $entityId,
            'after' => $after,
            'ip_address' => null,
            'user_agent' => 'stripe-webhook',
        ]);
    }
}
