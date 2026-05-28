<?php

namespace App\Services;

class InvoiceService
{
    public function nextInvoiceNumber(int $billingEntityId): string
    {
        return '';
    }

    public function createInvoice(array $data): int
    {
        return 0;
    }

    public function recalculateTotals(int $invoiceId): void {}

    public function send(int $invoiceId): void {}

    public function markPaid(int $invoiceId, array $paymentData): void {}

    public function generatePdf(int $invoiceId): string
    {
        return '';
    }
}
