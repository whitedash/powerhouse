<?php

namespace App\Http\Controllers\Internal;

use App\Events\PaginatedListAccessed;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    private const STATUSES = ['draft', 'sent', 'paid', 'overdue', 'void'];

    private const TYPES = ['subscription', 'service'];

    private const PAYMENT_METHODS = ['bank_transfer', 'card', 'direct_debit', 'other'];

    private const SORT_OPTIONS = ['created_at', 'due_date', 'total', 'customer'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Invoice::class);

        if ($request->user()) {
            PaginatedListAccessed::dispatch($request->user()->id, $request->path());
        }

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => in_array($request->query('status'), self::STATUSES, true)
                ? $request->query('status')
                : null,
            'billing_entity_id' => $request->query('billing_entity_id')
                ? (int) $request->query('billing_entity_id')
                : null,
            'type' => in_array($request->query('type'), self::TYPES, true)
                ? $request->query('type')
                : null,
            'sort' => in_array($request->query('sort'), self::SORT_OPTIONS, true)
                ? $request->query('sort')
                : 'created_at',
            'per_page' => (int) ($request->query('per_page') ?: 20),
        ];

        $query = Invoice::query()
            ->with([
                'customer:id,name,city',
                'billingEntity:id,name',
                'lines:id,invoice_id,description,sort_order',
            ]);

        if ($filters['search'] !== '') {
            $needle = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($needle) {
                $q->where('number', 'like', $needle)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $needle));
            });
        }

        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }

        if ($filters['billing_entity_id']) {
            $query->where('billing_entity_id', $filters['billing_entity_id']);
        }

        if ($filters['type']) {
            $query->where('type', $filters['type']);
        }

        match ($filters['sort']) {
            'due_date' => $query->orderByDesc('due_date'),
            'total' => $query->orderByDesc('total'),
            'customer' => $query->orderBy(
                Customer::select('name')->whereColumn('customers.id', 'invoices.customer_id'),
                'asc'
            ),
            default => $query->orderByDesc('created_at'),
        };

        $paginator = $query->paginate($filters['per_page'])->withQueryString();
        $today = Carbon::today();

        $paginator->through(function (Invoice $invoice) use ($today) {
            $firstLine = $invoice->lines->first();
            $daysOverdue = $invoice->status === 'overdue' && $invoice->due_date
                ? $today->diffInDays($invoice->due_date, false) * -1
                : null;
            $isDueToday = $invoice->status === 'sent' && $invoice->due_date?->isSameDay($today);

            return [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'type' => $invoice->type,
                'status' => $invoice->status,
                'subtotal' => (float) $invoice->subtotal,
                'vat_amount' => (float) $invoice->vat_amount,
                'total' => (float) $invoice->total,
                'amount_paid' => (float) $invoice->amount_paid,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'paid_at' => $invoice->paid_at?->toIso8601String(),
                'created_at' => $invoice->created_at?->toIso8601String(),
                'customer' => $invoice->customer
                    ? [
                        'id' => $invoice->customer->id,
                        'name' => $invoice->customer->name,
                        'city' => $invoice->customer->city,
                    ]
                    : null,
                'billing_entity' => $invoice->billingEntity
                    ? ['id' => $invoice->billingEntity->id, 'name' => $invoice->billingEntity->name]
                    : null,
                'description' => $firstLine?->description ?: 'No items',
                'days_overdue' => $daysOverdue !== null ? (int) max(1, $daysOverdue) : null,
                'is_due_today' => (bool) $isDueToday,
            ];
        });

        return Inertia::render('Internal/Invoices/Index', [
            'invoices' => $paginator,
            'summary' => $this->buildSummary(),
            'billing_entities' => BillingEntity::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'types' => self::TYPES,
            'entity_counts' => BillingEntity::where('is_active', true)
                ->withCount('invoices')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (BillingEntity $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'count' => $e->invoices_count,
                ]),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Invoice::class);

        $today = Carbon::today();

        $customers = Customer::whereNull('archived_at')
            ->with('primaryContact:id,customer_id,email')
            ->orderBy('name')
            ->get(['id', 'name', 'city', 'country', 'created_at'])
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'city' => $c->city,
                'country' => $c->country,
                'billing_email' => $c->primaryContact?->email,
                'created_at' => $c->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Internal/Invoices/Create', [
            'customers' => $customers,
            'billing_entities' => BillingEntity::where('is_active', true)
                ->orderBy('name')
                ->get([
                    'id', 'name', 'legal_name', 'company_number', 'vat_number',
                    'address', 'bank_name', 'sort_code', 'account_number',
                    'account_name', 'postmark_sender_email',
                ]),
            'next_number' => $this->previewNextInvoiceNumber(),
            'today' => $today->toDateString(),
            'default_due_date' => $today->copy()->addDays(14)->toDateString(),
            'vat_rates' => [0, 5, 20],
            'payment_terms' => ['Net 7', 'Net 14', 'Net 30', 'Due on receipt'],
            'types' => self::TYPES,
            'preselected_customer_id' => $request->query('customer_id')
                ? (int) $request->query('customer_id')
                : null,
        ]);
    }

    public function edit(int $id): Response
    {
        $invoice = Invoice::with([
            'lines' => fn ($q) => $q->orderBy('sort_order'),
            'customer:id,name,city',
            'billingEntity:id,name',
        ])->findOrFail($id);

        // Update policy is draft-only — non-draft will 403 here.
        Gate::authorize('update', $invoice);

        $today = Carbon::today();

        $customers = Customer::whereNull('archived_at')
            ->with('primaryContact:id,customer_id,email')
            ->orderBy('name')
            ->get(['id', 'name', 'city', 'country', 'created_at'])
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'city' => $c->city,
                'country' => $c->country,
                'billing_email' => $c->primaryContact?->email,
                'created_at' => $c->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Internal/Invoices/Edit', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'type' => $invoice->type,
                'status' => $invoice->status,
                'customer_id' => $invoice->customer_id,
                'billing_entity_id' => $invoice->billing_entity_id,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'vat_rate' => (float) $invoice->vat_rate,
                'notes' => $invoice->notes,
                'lines' => $invoice->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'description' => $l->description,
                    'note' => $l->note,
                    'quantity' => (float) $l->quantity,
                    'unit_price' => (float) $l->unit_price,
                    'sort_order' => (int) $l->sort_order,
                ])->values(),
            ],
            'customers' => $customers,
            'billing_entities' => BillingEntity::where('is_active', true)
                ->orderBy('name')
                ->get([
                    'id', 'name', 'legal_name', 'company_number', 'vat_number',
                    'address', 'bank_name', 'sort_code', 'account_number',
                    'account_name', 'postmark_sender_email',
                ]),
            'next_number' => $invoice->number,
            'today' => $today->toDateString(),
            'default_due_date' => $today->copy()->addDays(14)->toDateString(),
            'vat_rates' => [0, 5, 20],
            'payment_terms' => ['Net 7', 'Net 14', 'Net 30', 'Due on receipt'],
            'types' => self::TYPES,
            'preselected_customer_id' => $invoice->customer_id,
        ]);
    }

    public function update(int $id, StoreInvoiceRequest $request): RedirectResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::authorize('update', $invoice);

        $data = $request->validated();
        $sendAfter = (bool) ($data['send_after_create'] ?? false);

        DB::transaction(function () use ($invoice, $data, $request, $sendAfter) {
            $lines = collect($data['lines']);
            $subtotal = $lines->reduce(
                fn (float $carry, array $l) => $carry + round((float) $l['quantity'] * (float) $l['unit_price'], 2),
                0.0,
            );
            $vatRate = (float) $data['vat_rate'];
            $vatAmount = round($subtotal * ($vatRate / 100), 2);
            $total = round($subtotal + $vatAmount, 2);

            $invoice->update([
                'customer_id' => $data['customer_id'],
                'billing_entity_id' => $data['billing_entity_id'],
                'type' => $data['type'],
                'subtotal' => $subtotal,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            // Sync lines: keep submitted IDs, delete the rest.
            $submittedIds = collect($data['lines'])
                ->pluck('id')
                ->filter()
                ->all();
            $invoice->lines()
                ->whereNotIn('id', $submittedIds ?: [0])
                ->delete();

            foreach ($data['lines'] as $i => $line) {
                $attributes = [
                    'invoice_id' => $invoice->id,
                    'description' => $line['description'],
                    'note' => $line['note'] ?? null,
                    'quantity' => (float) $line['quantity'],
                    'unit_price' => (float) $line['unit_price'],
                    'amount' => round((float) $line['quantity'] * (float) $line['unit_price'], 2),
                    'sort_order' => $i,
                ];

                if (! empty($line['id'])) {
                    InvoiceLine::where('id', $line['id'])
                        ->where('invoice_id', $invoice->id)
                        ->update($attributes);
                } else {
                    InvoiceLine::create($attributes);
                }
            }

            $this->logActivity($request, 'invoice.updated', $invoice, after: [
                'total' => $total,
            ]);

            if ($sendAfter && $invoice->status === 'draft') {
                $invoice->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                $this->logActivity($request, 'invoice.sent', $invoice, after: [
                    'number' => $invoice->number,
                ]);

                Cache::forget('nav.invoices_outstanding');
            }
        });

        return redirect()
            ->route('internal.invoices.show', $invoice->id)
            ->with('success', "Invoice {$invoice->number} updated.");
    }

    public function downloadPdf(int $id, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $invoice = $this->loadInvoiceForPdf($id);
        $this->logActivity($request, 'invoice.pdf_downloaded', $invoice);

        return $this->buildPdf($invoice)->download('invoice-'.$invoice->number.'.pdf');
    }

    public function previewPdf(int $id, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $invoice = $this->loadInvoiceForPdf($id);
        $this->logActivity($request, 'invoice.pdf_previewed', $invoice);

        // ->stream() emits Content-Disposition: inline so the browser
        // opens the PDF in a new tab instead of forcing a download.
        return $this->buildPdf($invoice)->stream('invoice-'.$invoice->number.'.pdf');
    }

    private function loadInvoiceForPdf(int $id): Invoice
    {
        $invoice = Invoice::with([
            'customer',
            'customer.primaryContact',
            'billingEntity',
            'lines' => fn ($q) => $q->orderBy('sort_order'),
        ])->findOrFail($id);

        Gate::authorize('view', $invoice);

        return $invoice;
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
            // 96 DPI gives dompdf a larger pixel canvas (794×1123 vs the
            // default 595×842 at 72 DPI), which reduces rounding clip on
            // wide columns. defaultFont must match what the blade declares.
            ->setOptions([
                'dpi' => 96,
                'defaultFont' => 'Arial',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
            ]);
    }

    /**
     * Build a base64 data URL for the entity logo.
     *
     * dompdf's default `chroot` is the dompdf vendor directory, so any
     * absolute filesystem path outside it (e.g. our storage/app/private
     * uploads) is silently rejected — the <img> just doesn't render.
     * Embedding as a data: URL sidesteps the chroot entirely.
     *
     * Returns null on missing path or missing file — the Blade then
     * falls back to the gold "W" brand mark.
     */
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

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        Gate::authorize('create', Invoice::class);

        $data = $request->validated();
        $sendAfter = (bool) ($data['send_after_create'] ?? false);

        $invoice = DB::transaction(function () use ($data, $request, $sendAfter) {
            // Pessimistic-lock the latest INV-#### row so two simultaneous
            // creates can't both claim the same number. The lock holds for
            // the rest of the transaction.
            $number = $this->generateNextInvoiceNumberLocked();

            $lines = collect($data['lines']);
            $subtotal = $lines->reduce(
                fn (float $carry, array $l) => $carry + round((float) $l['quantity'] * (float) $l['unit_price'], 2),
                0.0,
            );
            $vatRate = (float) $data['vat_rate'];
            $vatAmount = round($subtotal * ($vatRate / 100), 2);
            $total = round($subtotal + $vatAmount, 2);

            $invoice = Invoice::create([
                'number' => $number,
                'customer_id' => $data['customer_id'],
                'billing_entity_id' => $data['billing_entity_id'],
                'type' => $data['type'],
                'status' => $sendAfter ? 'sent' : 'draft',
                'subtotal' => $subtotal,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'amount_paid' => 0,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'notes' => $data['notes'] ?? null,
                'sent_at' => $sendAfter ? now() : null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['lines'] as $i => $line) {
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'description' => $line['description'],
                    'note' => $line['note'] ?? null,
                    'quantity' => (float) $line['quantity'],
                    'unit_price' => (float) $line['unit_price'],
                    'amount' => round((float) $line['quantity'] * (float) $line['unit_price'], 2),
                    'sort_order' => $i,
                ]);
            }

            $this->logActivity($request, 'invoice.created', $invoice, after: [
                'number' => $number,
                'total' => $total,
                'customer_id' => $data['customer_id'],
            ]);

            if ($sendAfter) {
                $this->logActivity($request, 'invoice.sent', $invoice, after: ['number' => $number]);
            }

            // Outstanding count changes for any new sent invoice; draft
            // doesn't affect the badge but the cache key cost is trivial.
            Cache::forget('nav.invoices_outstanding');

            return $invoice;
        });

        return redirect()
            ->route('internal.invoices.show', $invoice->id)
            ->with('success', "Invoice {$invoice->number} created successfully.");
    }

    public function show(int $id): Response
    {
        $invoice = Invoice::with([
            'customer:id,name,city,country,address_line1,address_line2,postcode',
            'customer.primaryContact:id,customer_id,email',
            'billingEntity',
            'lines' => fn ($q) => $q->orderBy('sort_order'),
            'createdBy:id,name,role',
        ])->findOrFail($id);

        Gate::authorize('view', $invoice);

        $today = Carbon::today();
        $daysOverdue = $invoice->status === 'overdue' && $invoice->due_date
            ? (int) ($today->diffInDays($invoice->due_date, false) * -1)
            : null;
        $amountDue = (float) $invoice->total - (float) $invoice->amount_paid;

        $be = $invoice->billingEntity;

        $activity = ActivityLog::where('entity_type', 'invoice')
            ->where('entity_id', $invoice->id)
            ->orderByDesc('created_at')
            ->get(['id', 'action', 'after', 'created_at', 'user_id'])
            ->map(fn (ActivityLog $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'after' => $a->after,
                'created_at' => $a->created_at?->toIso8601String(),
                'user_id' => $a->user_id,
            ]);

        return Inertia::render('Internal/Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'type' => $invoice->type,
                'status' => $invoice->status,
                'subtotal' => (float) $invoice->subtotal,
                'vat_rate' => (float) $invoice->vat_rate,
                'vat_amount' => (float) $invoice->vat_amount,
                'total' => (float) $invoice->total,
                'amount_paid' => (float) $invoice->amount_paid,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'paid_at' => $invoice->paid_at?->toIso8601String(),
                'payment_method' => $invoice->payment_method,
                'payment_reference' => $invoice->payment_reference,
                'notes' => $invoice->notes,
                'pdf_path' => $invoice->pdf_path,
                'sent_at' => $invoice->sent_at?->toIso8601String(),
                'created_at' => $invoice->created_at?->toIso8601String(),

                'reminder_count' => $invoice->reminder_count,
                'last_reminder_sent_at' => $invoice->last_reminder_sent_at?->toIso8601String(),
                'next_reminder_at' => $invoice->next_reminder_at?->toIso8601String(),
                'reminders_paused' => (bool) $invoice->reminders_paused,
                'reminder_sent_today' => $invoice->last_reminder_sent_at?->isToday() ?? false,

                'days_overdue' => $daysOverdue !== null ? max(1, $daysOverdue) : null,
                'amount_due' => $amountDue,

                'customer' => $invoice->customer
                    ? [
                        'id' => $invoice->customer->id,
                        'name' => $invoice->customer->name,
                        'address_line1' => $invoice->customer->address_line1,
                        'address_line2' => $invoice->customer->address_line2,
                        'city' => $invoice->customer->city,
                        'postcode' => $invoice->customer->postcode,
                        'country' => $invoice->customer->country,
                        'billing_email' => $invoice->customer->primaryContact?->email,
                    ]
                    : null,

                // Bank details are decrypted automatically by the encrypted cast;
                // they're sent to the customer-facing invoice document. Anything
                // we log later (e.g. via Log::info) goes through the
                // RedactSensitiveData processor that ships from Security Sprint 1.
                'billing_entity' => $be
                    ? [
                        'id' => $be->id,
                        'name' => $be->name,
                        'legal_name' => $be->legal_name,
                        'company_number' => $be->company_number,
                        'vat_number' => $be->vat_number,
                        'address' => $be->address,
                        'bank_name' => $be->bank_name,
                        'sort_code' => $be->sort_code,
                        'account_number' => $be->account_number,
                        'account_name' => $be->account_name,
                        'postmark_sender_email' => $be->postmark_sender_email,
                    ]
                    : null,

                'lines' => $invoice->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'description' => $l->description,
                    'note' => $l->note,
                    'quantity' => (float) $l->quantity,
                    'unit_price' => (float) $l->unit_price,
                    'amount' => (float) $l->amount,
                    'sort_order' => (int) $l->sort_order,
                ])->values(),

                'created_by' => $invoice->createdBy
                    ? ['name' => $invoice->createdBy->name, 'role' => $invoice->createdBy->role]
                    : null,

                'activity' => $activity,
            ],
            'payment_methods' => self::PAYMENT_METHODS,
        ]);
    }

    public function markPaid(int $id, Request $request): RedirectResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::authorize('markPaid', $invoice);

        if (in_array($invoice->status, ['paid', 'void'], true)) {
            return back()->with('error', "Invoice {$invoice->number} is already {$invoice->status}.");
        }

        $data = $request->validate([
            'amount_received' => ['required', 'numeric', 'min:0.01', 'max:'.$invoice->total],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', 'in:'.implode(',', self::PAYMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($invoice, $data, $request) {
            $invoice->update([
                'status' => 'paid',
                'amount_paid' => $data['amount_received'],
                'paid_at' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'payment_reference' => $data['reference'] ?? null,
            ]);

            $this->logActivity($request, 'invoice.marked_paid', $invoice, after: [
                'amount' => (float) $data['amount_received'],
                'method' => $data['payment_method'],
            ]);

            $this->forgetNavInvoiceCaches();
        });

        return back()->with('success', "Invoice {$invoice->number} marked as paid.");
    }

    public function voidInvoice(int $id, Request $request): RedirectResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::authorize('void', $invoice);

        if ($invoice->status === 'paid') {
            return back()->with('error', 'Cannot void a paid invoice.');
        }

        if ($invoice->status === 'void') {
            return back()->with('error', 'Invoice is already void.');
        }

        DB::transaction(function () use ($invoice, $request) {
            $invoice->update(['status' => 'void']);

            $this->logActivity($request, 'invoice.voided', $invoice, after: [
                'number' => $invoice->number,
            ]);

            $this->forgetNavInvoiceCaches();
        });

        return back()->with('success', "Invoice {$invoice->number} voided.");
    }

    public function sendInvoice(int $id, Request $request): RedirectResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::authorize('send', $invoice);

        if ($invoice->status !== 'draft') {
            return back()->with('error', "Only draft invoices can be sent. Current status: {$invoice->status}.");
        }

        DB::transaction(function () use ($invoice, $request) {
            $invoice->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            $this->logActivity($request, 'invoice.sent', $invoice, after: [
                'number' => $invoice->number,
            ]);

            // Outstanding count changes (draft → sent moves the row into the
            // outstanding bucket the badge counts).
            Cache::forget('nav.invoices_outstanding');
        });

        return back()->with('success', 'Invoice marked as sent. Email delivery will be added in a future sprint.');
    }

    public function sendReminder(int $id, Request $request): RedirectResponse
    {
        $invoice = Invoice::with('customer.primaryContact')->findOrFail($id);
        Gate::authorize('sendReminder', $invoice);

        // Throttle: one manual reminder per calendar day per invoice.
        // Stops staff hammering the button at a customer and lets the
        // automated scheduler set the cadence after that.
        if ($invoice->last_reminder_sent_at && $invoice->last_reminder_sent_at->isToday()) {
            return back()->with('error', 'A reminder was already sent today for this invoice.');
        }

        $contact = $invoice->customer->primaryContact;
        $recipient = $contact ? $contact->email : 'unknown';

        DB::transaction(function () use ($invoice, $request, $recipient) {
            $invoice->increment('reminder_count');
            $invoice->update([
                'last_reminder_sent_at' => now(),
                'next_reminder_at' => now()->addDays(7),
            ]);

            // The dashboard polls for overdue every page load via cache.
            // If this is the first time we noticed the due date passed,
            // flip status here so the nav badges and lists catch up.
            if ($invoice->status === 'sent' && $invoice->due_date && $invoice->due_date->isPast()) {
                $invoice->update(['status' => 'overdue']);
                Cache::forget('nav.invoices_overdue');
                Cache::forget('nav.invoices_outstanding');
            }

            $this->logActivity($request, 'invoice.reminder_sent', $invoice, after: [
                'reminder_count' => $invoice->reminder_count,
                'recipient' => $recipient,
                'method' => 'manual',
            ]);

            // TODO: queue Postmark email here when the email sprint runs.
            // InvoiceReminderMail::dispatch($invoice, 'manual');
        });

        $name = $invoice->customer->name;

        return back()->with(
            'success',
            "Reminder logged for {$name}. Email delivery will be added in a future sprint.",
        );
    }

    public function pauseReminders(int $id, Request $request): RedirectResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::authorize('manageReminders', $invoice);

        if ($invoice->reminders_paused) {
            return back();
        }

        DB::transaction(function () use ($invoice, $request) {
            $invoice->update(['reminders_paused' => true]);
            $this->logActivity($request, 'invoice.reminders_paused', $invoice, after: [
                'number' => $invoice->number,
            ]);
        });

        return back()->with('success', 'Auto-reminders paused for this invoice.');
    }

    public function resumeReminders(int $id, Request $request): RedirectResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::authorize('manageReminders', $invoice);

        if (! $invoice->reminders_paused) {
            return back();
        }

        DB::transaction(function () use ($invoice, $request) {
            $invoice->update(['reminders_paused' => false]);
            $this->logActivity($request, 'invoice.reminders_resumed', $invoice, after: [
                'number' => $invoice->number,
            ]);
        });

        return back()->with('success', 'Auto-reminders resumed for this invoice.');
    }

    /**
     * Read-only preview of the next INV-#### number for the create form.
     * The authoritative number is assigned inside the store() transaction.
     */
    private function previewNextInvoiceNumber(): string
    {
        $last = Invoice::where('number', 'like', 'INV-%')
            ->orderByDesc('id')
            ->value('number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return 'INV-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Hot path during store(): pessimistic-lock the latest INV-#### row,
     * derive the next number, return it. Must be called inside an open
     * DB::transaction so the lock survives until COMMIT.
     */
    private function generateNextInvoiceNumberLocked(): string
    {
        $last = Invoice::where('number', 'like', 'INV-%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return 'INV-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function buildSummary(): array
    {
        $today = Carbon::today();

        return [
            'total_raised' => Invoice::whereMonth('created_at', $today->month)
                ->whereYear('created_at', $today->year)
                ->count(),
            'total_paid' => (float) Invoice::where('status', 'paid')
                ->whereMonth('paid_at', $today->month)
                ->whereYear('paid_at', $today->year)
                ->sum('total'),
            'outstanding_count' => Invoice::where('status', 'sent')->count(),
            'outstanding_amount' => (float) Invoice::where('status', 'sent')->sum('total'),
            'overdue_count' => Invoice::where('status', 'overdue')->count(),
            'overdue_amount' => (float) Invoice::where('status', 'overdue')->sum('total'),
        ];
    }

    private function logActivity(
        Request $request,
        string $action,
        Invoice $invoice,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }

    private function forgetNavInvoiceCaches(): void
    {
        Cache::forget('nav.invoices_overdue');
        Cache::forget('nav.invoices_outstanding');
    }
}
