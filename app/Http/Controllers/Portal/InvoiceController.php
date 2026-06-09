<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\Invoice;
use App\Models\PortalUser;
use App\Services\StripeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        // EnsurePortalUser guarantees the guard is authenticated, so we
        // can lean on the PortalUser annotation without a runtime guard.
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $customerId = $portalUser->customer_id;
        $outstandingStatuses = ['sent', 'overdue', 'partially_paid'];

        // Filter-tab counts span the whole account, not just the current
        // page — the tabs show a total, then the table paginates within it.
        $counts = [
            'all' => Invoice::where('customer_id', $customerId)->count(),
            'outstanding' => Invoice::where('customer_id', $customerId)->whereIn('status', $outstandingStatuses)->count(),
            'paid' => Invoice::where('customer_id', $customerId)->where('status', 'paid')->count(),
        ];

        // Active filter tab. Anything unexpected falls back to "all".
        $filter = (string) $request->query('filter', 'all');
        if (! in_array($filter, ['all', 'outstanding', 'paid'], true)) {
            $filter = 'all';
        }

        $query = Invoice::where('customer_id', $customerId)
            ->with('billingEntity:id,name');
        if ($filter === 'outstanding') {
            $query->whereIn('status', $outstandingStatuses);
        } elseif ($filter === 'paid') {
            $query->where('status', 'paid');
        }

        $invoices = $query
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Invoice $inv) => [
                'id' => $inv->id,
                'number' => $inv->number,
                'billing_entity' => $inv->billingEntity?->name,
                'issue_date' => $inv->issue_date?->format('j M Y'),
                'due_date' => $inv->due_date?->format('j M Y'),
                'total' => round((float) $inv->total, 2),
                'status' => $inv->status,
                'is_overdue' => $inv->status === 'overdue',
            ]);

        $summary = [
            'total_outstanding' => round((float) Invoice::where('customer_id', $customerId)
                ->whereIn('status', ['sent', 'overdue'])
                ->sum('total'), 2),
            'overdue_count' => Invoice::where('customer_id', $customerId)
                ->where('status', 'overdue')
                ->count(),
        ];

        return Inertia::render('Portal/Invoices', [
            'invoices' => $invoices,
            'summary' => $summary,
            'counts' => $counts,
            'filter' => $filter,
        ]);
    }

    /**
     * In-portal invoice detail page. Renders the invoice as HTML (not the
     * PDF) so the "Pay now" action can open the embedded Stripe Checkout
     * modal inline rather than redirecting to a hosted page. Scoped to the
     * portal user's customer_id — findOrFail 404s on any other invoice.
     */
    public function show(int $id): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $invoice = Invoice::with([
            // Bank columns included so the "Bank transfer" block can render the
            // entity's receive-payment details (encrypted at rest, decrypted on
            // read). These are customer-facing payment details, not secrets.
            'billingEntity:id,name,bank_name,sort_code,account_number,account_name,iban,bic',
            'customer:id,name,address_line1,address_line2,city,postcode,country',
            'lines' => fn ($q) => $q->orderBy('sort_order'),
        ])
            ->where('customer_id', $portalUser->customer_id)
            ->findOrFail($id);

        $total = (float) $invoice->total;
        $amountPaid = (float) $invoice->amount_paid;

        // "Billed to" block on the redesigned document — the customer's
        // name + a single-line address assembled from the parts we have.
        $cust = $invoice->customer;
        $addressLine = $cust ? implode(', ', array_filter([
            $cust->address_line1,
            $cust->address_line2,
            $cust->city,
            $cust->postcode,
            $cust->country,
        ])) : null;

        return Inertia::render('Portal/InvoiceDetail', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'billing_entity' => $invoice->billingEntity?->name,
                // Bank-transfer details for the issuing entity — only when at
                // least one field is populated (no empty block otherwise).
                'bank' => $this->bankBlock($invoice->billingEntity),
                'billed_to_name' => $cust?->name,
                'billed_to_address' => $addressLine ?: null,
                'status' => $invoice->status,
                'issue_date' => $invoice->issue_date?->format('j M Y'),
                'due_date' => $invoice->due_date?->format('j M Y'),
                'paid_at' => $invoice->paid_at?->format('j M Y'),
                'subtotal' => round((float) $invoice->subtotal, 2),
                'vat_rate' => (float) $invoice->vat_rate,
                'vat_amount' => round((float) $invoice->vat_amount, 2),
                'total' => round($total, 2),
                'amount_paid' => round($amountPaid, 2),
                'amount_due' => round($total - $amountPaid, 2),
                'is_payable' => in_array($invoice->status, ['sent', 'overdue', 'partially_paid'], true),
                'lines' => $invoice->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'description' => $l->description,
                    'note' => $l->note,
                    'quantity' => (float) $l->quantity,
                    'unit_price' => round((float) $l->unit_price, 2),
                    'amount' => round((float) $l->amount, 2),
                ])->values(),
            ],
        ]);
    }

    /**
     * Mint an embedded Stripe Checkout session for an unpaid invoice and
     * return its client_secret as JSON. The portal "Pay now" button fetches
     * this, then mounts Stripe Embedded Checkout inline (see
     * StripeCheckoutModal.vue). Secrets are short-lived, so we create a
     * fresh one on every request and never persist it.
     */
    public function checkoutSession(int $id, StripeService $stripe): JsonResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $invoice = Invoice::with('customer.primaryContact')
            ->where('customer_id', $portalUser->customer_id)
            ->findOrFail($id);

        abort_unless(
            in_array($invoice->status, ['sent', 'overdue', 'partially_paid'], true),
            422,
            'This invoice is not awaiting payment.',
        );

        try {
            $clientSecret = $stripe->createEmbeddedCheckoutSession($invoice);
        } catch (\Throwable $e) {
            // Stripe outage / API error must not surface as a 500 to the
            // customer mid-payment — return a clean, retryable signal.
            Log::error('Embedded Checkout session creation failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Payment is temporarily unavailable. Please try again shortly.',
            ], 503);
        }

        return response()->json(['client_secret' => $clientSecret]);
    }

    /**
     * Stripe success_url landing page. The webhook is the authoritative
     * settlement path, but it can arrive after the customer's browser does,
     * so we also verify the returned session directly with Stripe and settle
     * on the spot if it's paid and the webhook hasn't beaten us to it. Both
     * paths short-circuit on an already-paid invoice, so there's no double
     * settle.
     */
    public function paid(int $id, Request $request, StripeService $stripe): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $invoice = Invoice::with('customer.primaryContact:id,customer_id,email')
            ->where('customer_id', $portalUser->customer_id)
            ->findOrFail($id);

        $sessionId = (string) $request->query('session_id', '');
        if ($invoice->status !== 'paid' && $sessionId !== '') {
            $this->confirmFromSession($invoice, $sessionId, $stripe);
            $invoice->refresh();
        }

        return Inertia::render('Portal/InvoicePaid', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'total' => round((float) $invoice->total, 2),
                'status' => $invoice->status,
                'paid_at' => $invoice->paid_at?->format('j M Y, H:i'),
                // Receipt detail for the confirmation screen. We don't store
                // card brand/last4 (PCI-minimising), so the reference is the
                // Stripe payment-intent id and the channel is paid_via.
                'paid_via' => $invoice->paid_via,
                'payment_ref' => $invoice->stripe_payment_intent_id,
                'receipt_email' => $invoice->customer?->primaryContact?->email,
            ],
        ]);
    }

    /**
     * Retrieve the Checkout session from Stripe and, if it genuinely paid
     * for THIS invoice, settle it. Verifying server-side (not trusting the
     * redirect) is what makes the success page safe — a customer can't mark
     * an invoice paid just by visiting the URL.
     */
    private function confirmFromSession(Invoice $invoice, string $sessionId, StripeService $stripe): void
    {
        Stripe::setApiKey((string) config('services.stripe.secret'));

        try {
            $session = StripeSession::retrieve($sessionId);
        } catch (\Throwable $e) {
            return;
        }

        $matchesInvoice = (string) ($session->metadata->invoice_id ?? $session->client_reference_id ?? '') === (string) $invoice->id;

        if ($session->payment_status === 'paid' && $matchesInvoice) {
            $stripe->markInvoicePaid(
                $invoice,
                (string) $session->id,
                (string) ($session->payment_intent ?? ''),
            );
        }
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

    private function resolveLogoPath(Invoice $invoice): ?string
    {
        // See Internal\InvoiceController::resolveLogoPath — dompdf chroot
        // rejects absolute filesystem paths outside its vendor dir, so
        // we embed the logo as a base64 data URL.
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

    /**
     * The issuing entity's bank-transfer details, or null when none are set
     * (so the portal renders no empty block).
     *
     * @return array<string, string|null>|null
     */
    private function bankBlock(?BillingEntity $entity): ?array
    {
        if ($entity === null) {
            return null;
        }

        $fields = [
            'bank_name' => $entity->bank_name,
            'account_name' => $entity->account_name,
            'sort_code' => $entity->sort_code,
            'account_number' => $entity->account_number,
            'iban' => $entity->iban,
            'bic' => $entity->bic,
        ];

        return collect($fields)->filter()->isEmpty() ? null : $fields;
    }
}
