<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Final warning (final_notice tier) — the account will be suspended in 24h
 * unless the outstanding balance is paid.
 */
class SuspensionWarning extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(
        public Invoice $invoice,
        public CustomerProduct $customerProduct,
    ) {}

    public function build(): self
    {
        $this->customerProduct->loadMissing(['product', 'customer']);
        $productName = $this->customerProduct->product->name ?? 'your';

        return $this
            ->subject('Important: Your '.$productName.' account will be suspended in 24 hours')
            ->view('emails.suspension-warning')
            ->with([
                ...$this->getEntityData(null),
                'productName' => $productName,
                'amount' => (float) $this->invoice->total - (float) ($this->invoice->amount_paid ?? 0),
                'invoiceNumber' => $this->invoice->number,
                'dueDate' => $this->invoice->due_date?->format('d M Y'),
                'payPortalUrl' => rtrim((string) config('app.url'), '/').'/portal',
            ]);
    }
}
