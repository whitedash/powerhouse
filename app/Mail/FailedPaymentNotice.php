<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Dunning email (Billing P3) — sent on every failed off-session collection
 * attempt. Tone is keyed by attempt number: a retry-pending nudge for the
 * early attempts, a suspension warning for the final one. Entity-branded like
 * InvoiceReminder so per-entity Postmark senders apply.
 */
class FailedPaymentNotice extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(
        public Invoice $invoice,
        public int $attempt,
        public int $maxAttempts,
        public bool $final = false,
    ) {}

    public function build(): self
    {
        $this->invoice->loadMissing(['customer.primaryContact', 'billingEntity']);

        $entity = $this->invoice->billingEntity;
        $subject = $this->final
            ? 'Action required: we couldn\'t collect payment for invoice '.$this->invoice->number
            : 'Payment failed for invoice '.$this->invoice->number.' — we\'ll try again';

        return $this
            ->subject($subject)
            ->view('emails.failed-payment')
            ->with([
                ...$this->getEntityData($entity),
                'invoice' => $this->invoice,
                'attempt' => $this->attempt,
                'maxAttempts' => $this->maxAttempts,
                'final' => $this->final,
                'contactName' => $this->invoice->customer?->primaryContact?->name
                    ?: $this->invoice->customer?->name,
                'dueDate' => $this->invoice->due_date?->format('d M Y'),
                'payPortalUrl' => rtrim((string) config('app.url'), '/').'/portal',
                'payLink' => $this->invoice->stripe_payment_link,
            ]);
    }
}
