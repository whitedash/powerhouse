<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
// The concrete document type and the facade differ only in case
// (PDF vs Pdf), which PHP treats as the same import alias — so the
// document class is aliased to avoid a use-statement collision.
use Barryvdh\DomPDF\PDF as DompdfDocument;
use Illuminate\Support\Facades\Storage;

/**
 * Single source for invoice PDF generation. Extracted from
 * InvoiceController so the controller's download/preview routes and the
 * email Mailables (InvoiceSent / PaymentReceived) render byte-identical
 * documents. The logo is embedded as a base64 data URL to sidestep
 * dompdf's chroot, exactly as the controller did.
 */
class InvoicePdfService
{
    public function build(Invoice $invoice): DompdfDocument
    {
        $invoice->loadMissing(['customer.primaryContact', 'billingEntity', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'address' => $invoice->billingEntity->address ?? [],
            'billing_email' => $invoice->customer?->primaryContact?->email,
            'logo_path' => $this->resolveLogoPath($invoice),
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'dpi' => 96,
                'defaultFont' => 'Arial',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
            ]);
    }

    /**
     * Raw PDF bytes — for attachData() on a Mailable.
     */
    public function output(Invoice $invoice): string
    {
        return $this->build($invoice)->output();
    }

    private function resolveLogoPath(Invoice $invoice): ?string
    {
        $path = $invoice->billingEntity?->logo_path;
        if (! $path) {
            return null;
        }

        $absolute = Storage::disk('private')->path($path);
        if (! file_exists($absolute)) {
            return null;
        }

        $mime = mime_content_type($absolute) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($absolute));
    }
}
