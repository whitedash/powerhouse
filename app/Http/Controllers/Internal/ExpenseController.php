<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Supplier;
use App\Services\FileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses — the cost ledger. Index + CRUD + approval workflow +
 * receipt download. Auto-creation from CommissionLedger is wired in
 * ReferrerController::markPaid (it calls into ::createFromCommission
 * below) so the books stay reconciled without a manual step.
 */
class ExpenseController extends Controller
{
    private const CATEGORIES = [
        'referral_commission', 'software', 'hosting', 'travel',
        'office', 'marketing', 'advertising', 'equipment', 'other',
    ];

    private const STATUSES = ['pending', 'approved', 'paid'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $expenses = Expense::query()
            ->with([
                'createdBy:id,name',
                'project:id,title',
                'customer:id,name',
                'supplier:id,name,type',
            ])
            ->when($request->string('category')->toString() !== '', fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->date('date_from'), fn ($q, $d) => $q->where('expense_date', '>=', $d))
            ->when($request->date('date_to'), fn ($q, $d) => $q->where('expense_date', '<=', $d))
            ->when($request->integer('project_id'), fn ($q, int $id) => $q->where('project_id', $id))
            ->orderByDesc('expense_date')
            ->paginate(25)
            ->withQueryString();

        // Headline numbers. by_category drives the breakdown pill on
        // the strip; reimbursable is the personal-balance sub-total
        // (what the team is owed back, but only when paid).
        $summary = [
            'total_this_month' => (float) Expense::where('expense_date', '>=', now()->startOfMonth())
                ->sum('total'),
            'pending_approval' => (float) Expense::where('status', 'pending')->sum('total'),
            'reimbursable_outstanding' => (float) Expense::where('is_reimbursable', true)
                ->where('status', '!=', 'paid')
                ->sum('total'),
            'by_category' => Expense::query()
                ->selectRaw('category, SUM(total) as total')
                ->groupBy('category')
                ->pluck('total', 'category')
                ->toArray(),
        ];

        $projects = Project::whereNull('archived_at')
            ->orderBy('title')
            ->get(['id', 'title']);

        $customers = Customer::whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        // Active suppliers feed the expense form's supplier picker and
        // drive the category/VAT auto-fill on the client side.
        $suppliers = Supplier::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'default_expense_category', 'default_vat_rate']);

        return Inertia::render('Internal/Expenses/Index', [
            'expenses' => $expenses->through(fn (Expense $e): array => $this->map($e)),
            'summary' => $summary,
            'suppliers' => $suppliers,
            'filters' => [
                'category' => $request->string('category')->toString(),
                'status' => $request->string('status')->toString(),
                'project_id' => $request->integer('project_id') ?: null,
                'date_from' => $request->string('date_from')->toString(),
                'date_to' => $request->string('date_to')->toString(),
            ],
            'projects' => $projects,
            'customers' => $customers,
            'categories' => self::CATEGORIES,
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request, FileUploadService $uploads): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        // When a supplier is chosen but no category was supplied, fall
        // back to the supplier's default before validation runs so the
        // stored row carries a sensible category.
        if ($request->filled('supplier_id') && ! $request->filled('category')) {
            // optional() keeps this null-safe at runtime (an invalid id
            // is caught later by the exists rule) without the nullsafe
            // operator larastan flags on find()'s non-null return type.
            $request->merge([
                'category' => optional(Supplier::find($request->integer('supplier_id')))->default_expense_category ?? 'other',
            ]);
        }

        $data = $this->validateRow($request);

        DB::transaction(function () use ($request, $data, $uploads) {
            $receiptPath = null;
            $receiptOriginalName = null;

            // Receipt upload runs through FileUploadService so we
            // get the same MIME/size/sanitisation pipeline that
            // logo + contract uploads use — never trust the
            // client-supplied filename or extension.
            if ($request->hasFile('receipt')) {
                $file = $request->file('receipt');
                $receiptPath = $uploads->store($file, 'receipt');
                $receiptOriginalName = $file->getClientOriginalName();
            }

            $vatAmount = round(((float) $data['amount']) * ((float) ($data['vat_rate'] ?? 0)) / 100, 2);

            Expense::create([
                ...$data,
                'vat_amount' => $vatAmount,
                // total recomputed in Expense::booted() but we set
                // it here too so the column doesn't briefly hold
                // null between insert + the saving hook.
                'total' => round((float) $data['amount'] + $vatAmount, 2),
                'receipt_path' => $receiptPath,
                'receipt_original_name' => $receiptOriginalName,
                'created_by' => $request->user()->id,
            ]);

            $this->log($request, 'expense.created', after: [
                'description' => $data['description'],
                'amount' => $data['amount'],
                'category' => $data['category'],
            ]);
        });

        return back()->with('success', 'Expense recorded.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $expense = Expense::findOrFail($id);
        $data = $this->validateRow($request, allowReceipt: false);

        $before = $expense->only(['description', 'amount', 'status', 'category']);
        $vatAmount = round(((float) $data['amount']) * ((float) ($data['vat_rate'] ?? 0)) / 100, 2);

        $expense->update([
            ...$data,
            'vat_amount' => $vatAmount,
        ]);

        $this->log($request, 'expense.updated', before: $before, after: $expense->only(['description', 'amount', 'status']));

        return back()->with('success', 'Expense updated.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $expense = Expense::findOrFail($id);

        DB::transaction(function () use ($expense, $request) {
            $snapshot = $expense->only(['description', 'amount', 'category']);
            $expense->delete();
            $this->log($request, 'expense.deleted', before: $snapshot);
        });

        return back()->with('success', 'Expense removed.');
    }

    public function approve(int $id, Request $request): RedirectResponse
    {
        // Approval is super_admin-only — it shifts an expense from
        // "raised" to "spendable" on the books.
        if (! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a super admin can approve expenses.');
        }

        $expense = Expense::findOrFail($id);
        if ($expense->status !== 'pending') {
            return back()->with('error', 'Only pending expenses can be approved.');
        }

        $expense->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
        ]);

        $this->log($request, 'expense.approved', after: ['expense_id' => $expense->id]);

        return back()->with('success', 'Expense approved.');
    }

    public function markPaid(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $expense = Expense::findOrFail($id);

        if ($expense->status === 'paid') {
            return back()->with('error', 'Expense already marked paid.');
        }

        $expense->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->log($request, 'expense.paid', after: ['expense_id' => $expense->id]);

        return back()->with('success', 'Expense marked paid.');
    }

    /**
     * Stream the receipt back to the operator. We don't proxy via a
     * signed URL because expenses live on the private disk; we
     * just inline the file with the correct Content-Type. Filename
     * comes from the column we stamped at upload, not the path
     * (which is a UUID).
     */
    public function receipt(int $id): StreamedResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $expense = Expense::findOrFail($id);

        abort_if($expense->receipt_path === null, 404, 'No receipt attached.');

        return Storage::disk('private')->download(
            $expense->receipt_path,
            $expense->receipt_original_name ?? basename($expense->receipt_path),
        );
    }

    /**
     * Public helper called by ReferrerController::markPaid so a
     * commission becomes a paid-expense row in one atomic step.
     * Idempotent — bails out if a matching row already exists,
     * so a double-click on the markPaid button doesn't double-log.
     */
    public static function createFromCommission(CommissionLedger $entry, int $createdBy): ?Expense
    {
        // Idempotency guard: if an expense was already auto-created
        // from this ledger row, return the existing record.
        $existing = Expense::where('commission_ledger_id', $entry->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        // referrer is non-null (referrer_id is NOT NULL); user under
        // referrer is also non-null in the schema. We keep a safe
        // fallback when the relations weren't eager-loaded (caller's
        // responsibility), but skip the nullsafe to satisfy phpstan.
        $referrerName = $entry->referrer->user->name;
        $customerName = $entry->customer?->name;

        return Expense::create([
            'category' => 'referral_commission',
            'description' => 'Referral commission — '.$referrerName.($customerName ? ' / '.$customerName : ''),
            'supplier_name' => $referrerName,
            'amount' => $entry->commission_amount,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total' => $entry->commission_amount,
            'expense_date' => now()->toDateString(),
            'status' => 'paid',
            'paid_at' => now(),
            'commission_ledger_id' => $entry->id,
            'created_by' => $createdBy,
            'customer_id' => $entry->customer_id,
            'notes' => 'Auto-created from commission ledger entry #'.$entry->id.'.',
        ]);
    }

    /**
     * Shared validation. `allowReceipt` toggles the file rule —
     * update() can't replace the receipt (operator should re-upload
     * to a new expense if the original was wrong).
     *
     * @return array<string, mixed>
     */
    private function validateRow(Request $request, bool $allowReceipt = true): array
    {
        $rules = [
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'description' => 'required|string|max:255',
            'supplier_name' => 'nullable|string|max:255',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'amount' => 'required|numeric|min:0',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'expense_date' => 'required|date',
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'is_reimbursable' => 'nullable|boolean',
            'project_id' => 'nullable|integer|exists:projects,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'notes' => 'nullable|string|max:2000',
        ];

        if ($allowReceipt) {
            $rules['receipt'] = 'nullable|file|max:5120|mimes:pdf,jpg,jpeg,png';
        }

        return $request->validate($rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function map(Expense $e): array
    {
        return [
            'id' => $e->id,
            'category' => $e->category,
            'description' => $e->description,
            'supplier_id' => $e->supplier_id,
            // Nullable belongsTo: branch with a truthy check (larastan
            // types the relation non-null, so a nullsafe op is flagged).
            'supplier_name' => $e->supplier ? $e->supplier->name : $e->supplier_name,
            'amount' => $e->amount,
            'vat_rate' => $e->vat_rate,
            'vat_amount' => $e->vat_amount,
            'total' => $e->total,
            'expense_date' => $e->expense_date->format('d M Y'),
            'expense_date_raw' => $e->expense_date->toDateString(),
            'status' => $e->status,
            'is_reimbursable' => $e->is_reimbursable,
            'has_receipt' => $e->receipt_path !== null,
            'receipt_name' => $e->receipt_original_name,
            'project' => $e->project ? ['id' => $e->project->id, 'title' => $e->project->title] : null,
            'customer' => $e->customer ? ['id' => $e->customer->id, 'name' => $e->customer->name] : null,
            'commission_ledger_id' => $e->commission_ledger_id,
            // created_by is NOT NULL so createdBy is always present;
            // drop the nullsafe to satisfy phpstan.
            'created_by_name' => $e->createdBy->name,
            'notes' => $e->notes,
            'paid_at' => $e->paid_at?->format('d M Y'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Request $request, string $action, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => $action,
            'entity_type' => 'expense',
            'entity_id' => $after['expense_id'] ?? 0,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
