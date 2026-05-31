<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Product ↔ Supplier cost lines. Manages the product_suppliers pivot
 * from the product detail page so the operator can track the underlying
 * cost behind each product (for margin reporting). Auth gates on the
 * product's own update policy — if you can edit the product, you can
 * manage its cost lines.
 */
class ProductSupplierController extends Controller
{
    private const INTERVALS = ['monthly', 'quarterly', 'annually', 'one_time'];

    /**
     * The product detail page (Settings/Products.vue) already receives
     * its cost lines inline via ProductController. This JSON endpoint is
     * the standalone fetch — used by future tooling / API consumers.
     */
    public function index(int $productId): JsonResponse
    {
        $product = Product::with(['suppliers'])->findOrFail($productId);
        Gate::authorize('update', $product);

        return response()->json([
            'product' => ['id' => $product->id, 'name' => $product->name],
            'suppliers' => $product->suppliers->map(fn ($s): array => $this->mapPivot($s))->values()->all(),
        ]);
    }

    public function store(int $productId, Request $request): RedirectResponse
    {
        $product = Product::findOrFail($productId);
        Gate::authorize('update', $product);

        $data = $this->validateRow($request, withSupplier: true);

        // Friendly guard — the composite PK is the DB backstop.
        if ($product->suppliers()->where('supplier_id', $data['supplier_id'])->exists()) {
            return back()->with('error', 'This supplier is already linked to this product.');
        }

        DB::transaction(function () use ($product, $data, $request) {
            $product->suppliers()->attach($data['supplier_id'], [
                'cost_per_unit' => $data['cost_per_unit'],
                'billing_interval' => $data['billing_interval'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->log($request, 'product_supplier.linked', $product->id, after: [
                'supplier_id' => $data['supplier_id'],
                'cost_per_unit' => $data['cost_per_unit'],
            ]);
        });

        return back()->with('success', 'Supplier cost added.');
    }

    public function update(int $productId, int $supplierId, Request $request): RedirectResponse
    {
        $product = Product::findOrFail($productId);
        Gate::authorize('update', $product);

        abort_unless($product->suppliers()->where('supplier_id', $supplierId)->exists(), 404);

        $data = $this->validateRow($request);

        DB::transaction(function () use ($product, $supplierId, $data, $request) {
            $product->suppliers()->updateExistingPivot($supplierId, [
                'cost_per_unit' => $data['cost_per_unit'],
                'billing_interval' => $data['billing_interval'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->log($request, 'product_supplier.updated', $product->id, after: [
                'supplier_id' => $supplierId,
                'cost_per_unit' => $data['cost_per_unit'],
            ]);
        });

        return back()->with('success', 'Supplier cost updated.');
    }

    public function destroy(int $productId, int $supplierId, Request $request): RedirectResponse
    {
        $product = Product::findOrFail($productId);
        Gate::authorize('update', $product);

        DB::transaction(function () use ($product, $supplierId, $request) {
            $product->suppliers()->detach($supplierId);
            $this->log($request, 'product_supplier.unlinked', $product->id, after: ['supplier_id' => $supplierId]);
        });

        return back()->with('success', 'Supplier cost removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRow(Request $request, bool $withSupplier = false): array
    {
        $rules = [
            'cost_per_unit' => 'required|numeric|min:0',
            'billing_interval' => ['required', Rule::in(self::INTERVALS)],
            'notes' => 'nullable|string|max:500',
        ];

        if ($withSupplier) {
            $rules['supplier_id'] = 'required|integer|exists:suppliers,id';
        }

        return $request->validate($rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPivot(Supplier $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'type' => $s->type,
            'cost_per_unit' => (float) $s->pivot->cost_per_unit,
            'billing_interval' => $s->pivot->billing_interval,
            'notes' => $s->pivot->notes,
        ];
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
            'entity_type' => 'product',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
