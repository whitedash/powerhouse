<?php

namespace App\Http\Controllers\Internal;

use App\Events\PaginatedListAccessed;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    public function create(): Response
    {
        Gate::authorize('create', Invoice::class);

        return Inertia::render('Internal/Invoices/Create');
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
