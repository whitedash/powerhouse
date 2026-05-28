<?php

namespace App\Http\Controllers\Internal;

use App\Events\PaginatedListAccessed;
use App\Http\Controllers\Controller;
use App\Models\BillingEntity;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    private const STATUSES = ['draft', 'sent', 'paid', 'overdue', 'void'];

    private const TYPES = ['subscription', 'service'];

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

        $summary = $this->buildSummary();

        return Inertia::render('Internal/Invoices/Index', [
            'invoices' => $paginator,
            'summary' => $summary,
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
        $invoice = Invoice::with(['customer:id,name', 'billingEntity:id,name', 'lines'])
            ->findOrFail($id);

        Gate::authorize('view', $invoice);

        return Inertia::render('Internal/Invoices/Show', ['id' => $invoice->id]);
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
}
