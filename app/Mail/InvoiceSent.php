<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\BillingEntity;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The invoice itself, with the PDF attached. The recipient is set by the
 * caller (Mail::to(...)); this Mailable only owns subject + body + branding.
 */
class InvoiceSent extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(public Invoice $invoice) {}

    public function build(): self
    {
        $this->invoice->loadMissing(['customer.primaryContact', 'billingEntity', 'lines.product']);

        $contact = $this->invoice->customer->primaryContact;
        $entity = $this->invoice->billingEntity;
        $pdf = app(InvoicePdfService::class)->output($this->invoice);

        return $this
            ->subject('Invoice '.$this->invoice->number.($entity ? ' from '.$entity->legal_name : ''))
            ->view('emails.invoice-sent')
            ->with([
                ...$this->getEntityData($entity),
                'invoice' => $this->invoice,
                'contactName' => $contact->name ?? $this->invoice->customer->name,
                'dueDate' => $this->invoice->due_date?->format('d M Y'),
                'paymentDetails' => $this->bankDetails($entity),
            ])
            ->attachData($pdf, $this->invoice->number.'.pdf', ['mime' => 'application/pdf']);
    }

    /**
     * Decrypted bank details for the payment block. Returns an empty array
     * when the entity has none, so the view hides the section.
     *
     * @return array<string, string>
     */
    private function bankDetails(?BillingEntity $entity): array
    {
        if ($entity === null) {
            return [];
        }

        return array_filter([
            'Account name' => $entity->account_name,
            'Sort code' => $entity->sort_code,
            'Account number' => $entity->account_number,
            'Bank' => $entity->bank_name,
        ], fn ($v) => ! empty($v));
    }
}
