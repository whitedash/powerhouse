<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerProduct;
use App\Models\Invoice;

/**
 * What PlanPurchaseService::settle() produced for one Checkout session.
 * receiptDue is true only when THIS call transitioned the invoice to paid
 * AND the subscription went live (status 'active') — the caller sends the
 * PlanPurchaseReceipt then and only then, so webhook replays and
 * pending-review purchases never mail.
 */
final readonly class PlanPurchaseResult
{
    public function __construct(
        public Invoice $invoice,
        public ?CustomerProduct $customerProduct,
        public Company $company,
        public ?string $contactEmail,
        public bool $receiptDue,
    ) {}
}
