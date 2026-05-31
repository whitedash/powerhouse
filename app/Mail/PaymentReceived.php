<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Payment receipt — sent when an invoice is marked fully paid. Re-attaches
 * the invoice PDF for the customer's records.
 */
class PaymentReceived extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(public Invoice $invoice) {}

    public function build(): self
    {
        $this->invoice->loadMissing(['customer.primaryContact', 'billingEntity', 'lines.product']);

        $contact = $this->invoice->customer->primaryContact;
        $pdf = app(InvoicePdfService::class)->output($this->invoice);

        return $this
            ->subject('Payment received — '.$this->invoice->number)
            ->view('emails.payment-received')
            ->with([
                ...$this->getEntityData($this->invoice->billingEntity),
                'invoice' => $this->invoice,
                'contactName' => $contact->name ?? $this->invoice->customer->name,
                'paidAt' => $this->invoice->paid_at?->format('d M Y') ?? now()->format('d M Y'),
            ])
            ->attachData($pdf, $this->invoice->number.'.pdf', ['mime' => 'application/pdf']);
    }
}
