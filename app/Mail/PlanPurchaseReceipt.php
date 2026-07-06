<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Welcome + receipt for a Plans-widget self-serve purchase
 * (PLANS-WIDGET-DESIGN.md §5). Purchase-specific by design: the shared
 * Stripe settlement path (markInvoicePaid) stays mail-silent so existing
 * invoice payments keep their current behaviour. Withheld while the
 * subscription is pending manual review; the confirm action sends it.
 */
class PlanPurchaseReceipt extends Mailable
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
        $this->invoice->loadMissing(['customer.primaryContact', 'billingEntity', 'lines.product']);
        $this->customerProduct->loadMissing(['product', 'productPlan']);

        $contact = $this->invoice->customer->primaryContact;
        $pdf = app(InvoicePdfService::class)->output($this->invoice);

        return $this
            ->subject('Welcome — your '.($this->customerProduct->product->name ?? 'plan').' purchase')
            ->view('emails.plan-purchase-receipt')
            ->with([
                ...$this->getEntityData($this->invoice->billingEntity),
                'invoice' => $this->invoice,
                'contactName' => $contact->name ?? $this->invoice->customer->name,
                'productName' => $this->customerProduct->product?->name,
                'planName' => $this->customerProduct->productPlan?->name,
                'paidAt' => $this->invoice->paid_at?->format('d M Y') ?? now()->format('d M Y'),
            ])
            ->attachData($pdf, $this->invoice->number.'.pdf', ['mime' => 'application/pdf']);
    }
}
