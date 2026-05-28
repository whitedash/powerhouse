<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invoice;
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
        $portalUser = Auth::guard('portal')->user();
        abort_unless($portalUser, 401);

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

        $address = $invoice->billingEntity?->address;
        if (is_string($address)) {
            $address = json_decode($address, true) ?: [];
        }

        ActivityLog::create([
            'user_id' => $portalUser->id,
            'user_role' => 'portal',
            'action' => 'invoice.pdf_downloaded',
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'address' => $address,
            'billing_email' => $invoice->customer?->primaryContact?->email,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('invoice-'.$invoice->number.'.pdf');
    }
}
