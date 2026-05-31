<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Suppliers — the vendor register. CRUD over the suppliers table plus
 * the headline summary strip. Auth mirrors the expenses module: the
 * surrounding route group gates to staff/super_admin, and each method
 * re-checks the Customer viewAny gate so direct API hits can't bypass
 * the financial-data boundary.
 *
 * The qbo_* fields are read/written here but never synced — a future
 * QuickBooks sprint owns that. We just keep the columns populated so the
 * UI can surface sync status today.
 */
class SupplierController extends Controller
{
    private const TYPES = [
        'software', 'hosting', 'marketing', 'domain_registrar',
        'finance', 'utilities', 'professional_services', 'other',
    ];

    // Mirrors the expenses.category enum — a supplier's default category
    // must be a value the expense form will accept.
    private const EXPENSE_CATEGORIES = [
        'referral_commission', 'software', 'hosting', 'travel',
        'office', 'marketing', 'advertising', 'equipment', 'other',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $suppliers = Supplier::query()
            ->with(['createdBy:id,name'])
            ->withCount('expenses')
            ->when($request->string('search')->toString() !== '', function ($q) use ($request) {
                $s = $request->string('search')->toString();
                $q->where(function ($w) use ($s) {
                    $w->where('name', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%");
                });
            })
            ->when($request->string('type')->toString() !== '', fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Supplier $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'contact_name' => $s->contact_name,
                'email' => $s->email,
                'phone' => $s->phone,
                'website' => $s->website,
                'account_number' => $s->account_number,
                'payment_terms' => $s->payment_terms,
                'default_expense_category' => $s->default_expense_category,
                'default_vat_rate' => $s->default_vat_rate,
                'notes' => $s->notes,
                'is_active' => $s->is_active,
                'expenses_count' => $s->expenses_count,
                'qbo_sync_status' => $s->qbo_sync_status,
                'qbo_vendor_id' => $s->qbo_vendor_id,
                'created_at' => $s->created_at?->format('d M Y'),
            ]);

        $summary = [
            'total' => Supplier::count(),
            'active' => Supplier::where('is_active', true)->count(),
            'unsynced' => Supplier::where('qbo_sync_status', 'not_synced')->count(),
        ];

        return Inertia::render('Internal/Suppliers/Index', [
            'suppliers' => $suppliers,
            'summary' => $summary,
            'filters' => [
                'search' => $request->string('search')->toString(),
                'type' => $request->string('type')->toString(),
                'active_only' => $request->boolean('active_only'),
            ],
            'types' => self::TYPES,
            'expense_categories' => self::EXPENSE_CATEGORIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $data = $this->validateRow($request);

        DB::transaction(function () use ($request, $data) {
            $supplier = Supplier::create([
                ...$data,
                'created_by' => $request->user()->id,
            ]);

            $this->log($request, 'supplier.created', $supplier->id, after: [
                'name' => $supplier->name,
                'type' => $supplier->type,
            ]);
        });

        return back()->with('success', 'Supplier created.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $supplier = Supplier::findOrFail($id);
        $data = $this->validateRow($request);

        $before = $supplier->only(['name', 'type', 'is_active']);

        $supplier->update($data);

        $this->log($request, 'supplier.updated', $supplier->id, before: $before, after: $supplier->only(['name', 'type', 'is_active']));

        return back()->with('success', 'Supplier updated.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $supplier = Supplier::findOrFail($id);

        // Block deletion when expenses reference this supplier — the FK
        // is nullOnDelete so the rows wouldn't break, but we don't want
        // to silently orphan an audited cost. Deactivation is the
        // reversible alternative.
        $expenseCount = $supplier->expenses()->count();
        if ($expenseCount > 0) {
            return back()->with('error', "Cannot delete supplier with {$expenseCount} linked expense".($expenseCount === 1 ? '' : 's').'. Deactivate instead.');
        }

        DB::transaction(function () use ($supplier, $request) {
            $snapshot = $supplier->only(['name', 'type']);
            $supplier->delete();
            $this->log($request, 'supplier.deleted', $supplier->id, before: $snapshot);
        });

        return back()->with('success', 'Supplier deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRow(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(self::TYPES)],
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:500',
            'address' => 'nullable|string|max:2000',
            'account_number' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:100',
            'default_expense_category' => ['nullable', Rule::in(self::EXPENSE_CATEGORIES)],
            'default_vat_rate' => 'required|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Request $request, string $action, int $entityId, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => $action,
            'entity_type' => 'supplier',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
