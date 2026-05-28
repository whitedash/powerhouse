<?php

namespace App\Services;

class CommissionService
{
    public function calculateForInvoice(int $invoiceId): void {}

    public function calculateMonthlyRecurring(string $period): void {}

    public function approve(int $ledgerEntryId, int $approvedBy): void {}

    public function markPaid(array $ledgerEntryIds): void {}
}
