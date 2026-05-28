<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\PortalUser;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Portal/Invoices');
    }

    public function downloadPdf(int $id, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$invoice, $portalUser] = $this->loadInvoiceForPdf($id);
        $this->logActivity($request, 'invoice.pdf_downloaded', $invoice, $portalUser);

        return $this->buildPdf($invoice)->download('invoice-'.$invoice->number.'.pdf');
    }

    public function previewPdf(int $id, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        [$invoice, $portalUser] = $this->loadInvoiceForPdf($id);
        $this->logActivity($request, 'invoice.pdf_previewed', $invoice, $portalUser);

        // ->stream() emits Content-Disposition: inline so the browser
        // opens the PDF in a new tab instead of forcing a download.
        return $this->buildPdf($invoice)->stream('invoice-'.$invoice->number.'.pdf');
    }

    /**
     * @return array{0: Invoice, 1: PortalUser}
     */
    private function loadInvoiceForPdf(int $id): array
    {
        /** @var PortalUser|null $portalUser */
        $portalUser = Auth::guard('portal')->user();
        abort_unless($portalUser instanceof PortalUser, 401);

        // EnsurePortalDataOwnership middleware can't catch this — the
        // route param is invoice id, not customer id — so the scoping
        // happens here: every query is constrained to the portal user's
        // customer_id. findOrFail then 404s on mismatch.
        $invoice = Invoice::with([
            'customer',
            'customer.primaryContact',
            'billingEntity',
            'lines' => fn ($q) => $q->orderBy('sort_order'),
        ])
            ->where('customer_id', $portalUser->customer_id)
            ->findOrFail($id);

        return [$invoice, $portalUser];
    }

    private function buildPdf(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'address' => $invoice->billingEntity->address ?? [],
            'billing_email' => $invoice->customer?->primaryContact?->email,
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'dpi' => 96,
                'defaultFont' => 'Arial',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
            ]);
    }

    private function logActivity(Request $request, string $action, Invoice $invoice, PortalUser $portalUser): void
    {
        ActivityLog::create([
            'user_id' => $portalUser->id,
            'user_role' => 'portal',
            'action' => $action,
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
