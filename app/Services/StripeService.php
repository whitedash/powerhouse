<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

/**
 * Thin wrapper over Stripe hosted checkout for invoice payments. Uses a
 * StripeClient instance (not the global \Stripe\Stripe::setApiKey state)
 * so the secret never leaks between requests. Amounts are always sent in
 * minor units (pence) — Stripe rejects fractional major units.
 */
class StripeService
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient((string) config('services.stripe.secret'));
    }

    /**
     * Hosted-checkout session for a single invoice's outstanding balance.
     * Persists the session id + URL back onto the invoice so the link can
     * be re-opened/copied without regenerating.
     */
    public function createCheckoutSession(Invoice $invoice, ?string $successUrl = null, ?string $cancelUrl = null): Session
    {
        $invoice->loadMissing(['customer.primaryContact', 'billingEntity']);

        $outstanding = $this->outstandingPence((float) $invoice->total, (float) ($invoice->amount_paid ?? 0));

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => $this->currency(),
                    'unit_amount' => $outstanding,
                    'product_data' => [
                        'name' => 'Invoice '.$invoice->number,
                        'description' => $invoice->billingEntity->legal_name ?? (string) config('app.name'),
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'invoice_id' => (string) $invoice->id,
                'powerhouse' => 'true',
            ],
            'customer_email' => $invoice->customer?->primaryContact?->email,
            'success_url' => $successUrl ?? $this->defaultSuccessUrl($invoice),
            'cancel_url' => $cancelUrl ?? $this->defaultCancelUrl($invoice),
        ]);

        $invoice->update([
            'stripe_checkout_session_id' => $session->id,
            'stripe_payment_link' => $session->url,
        ]);

        return $session;
    }

    /**
     * Single session covering every outstanding invoice for a customer —
     * used by the suspension "pay everything now" flow. metadata carries
     * the full id list so the webhook can settle all of them at once.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    public function createOutstandingCheckoutSession(Customer $customer, Collection $invoices, ?string $successUrl = null, ?string $cancelUrl = null): Session
    {
        $customer->loadMissing('primaryContact');

        $totalOutstanding = $invoices->sum(
            fn (Invoice $i): int => $this->outstandingPence((float) $i->total, (float) ($i->amount_paid ?? 0))
        );

        $ids = $invoices->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => $this->currency(),
                    'unit_amount' => (int) $totalOutstanding,
                    'product_data' => [
                        'name' => 'Outstanding balance — '.count($ids).' invoice(s)',
                        'description' => (string) config('app.name'),
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'invoice_ids' => implode(',', $ids),
                'customer_id' => (string) $customer->id,
                'powerhouse' => 'true',
            ],
            'customer_email' => $customer->primaryContact?->email,
            'success_url' => $successUrl ?? (string) config('app.url'),
            'cancel_url' => $cancelUrl ?? (string) config('app.url'),
        ]);
    }

    private function outstandingPence(float $total, float $amountPaid): int
    {
        return (int) round(max($total - $amountPaid, 0) * 100);
    }

    private function currency(): string
    {
        return (string) config('services.stripe.currency', 'gbp');
    }

    private function defaultSuccessUrl(Invoice $invoice): string
    {
        return config('app.url').'/invoices/'.$invoice->id.'?payment=success';
    }

    private function defaultCancelUrl(Invoice $invoice): string
    {
        return config('app.url').'/invoices/'.$invoice->id.'?payment=cancelled';
    }
}
