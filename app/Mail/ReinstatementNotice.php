<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\Customer;
use App\Models\CustomerProduct;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when a suspended account is reinstated and access restored.
 */
class ReinstatementNotice extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(
        public CustomerProduct $customerProduct,
        public Customer $customer,
    ) {}

    public function build(): self
    {
        $this->customerProduct->loadMissing('product');
        $productName = $this->customerProduct->product->name ?? 'your';

        return $this
            ->subject('Your '.$productName.' account has been reinstated')
            ->view('emails.reinstatement-notice')
            ->with([
                ...$this->getEntityData(null),
                'productName' => $productName,
                'customerName' => $this->customer->name,
                'accessUrl' => rtrim((string) config('app.url'), '/').'/portal',
            ]);
    }
}
