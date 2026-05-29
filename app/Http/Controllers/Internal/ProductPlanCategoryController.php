<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ProductPlan;
use App\Models\ProductPlanCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductPlanCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('product_plan_categories', 'name')->where('product_id', $request->product_id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer'],
            'is_public' => ['boolean'],
        ]);

        $cat = DB::transaction(function () use ($data, $request) {
            $cat = ProductPlanCategory::create($data);
            $this->logActivity($request, 'plan_category.created', $cat->product_id, after: [
                'category_id' => $cat->id,
                'name' => $cat->name,
            ]);

            return $cat;
        });

        return back()->with('success', "Category '{$cat->name}' added.");
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $cat = ProductPlanCategory::findOrFail($id);

        // product_id is fixed at create. Renaming has to stay unique
        // within the same product.
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('product_plan_categories', 'name')
                    ->where('product_id', $cat->product_id)
                    ->ignore($cat->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer'],
            'is_public' => ['boolean'],
        ]);

        DB::transaction(function () use ($cat, $data, $request) {
            $before = ['name' => $cat->name, 'is_public' => $cat->is_public];
            $cat->update($data);
            $this->logActivity($request, 'plan_category.updated', $cat->product_id, $before, [
                'category_id' => $cat->id,
                'name' => $cat->name,
            ]);
        });

        return back()->with('success', "Category '{$cat->name}' updated.");
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $cat = ProductPlanCategory::findOrFail($id);

        DB::transaction(function () use ($cat, $request) {
            // Orphan handling: detach plans rather than deleting them.
            // The FK is SET NULL on the column anyway, so this is
            // belt-and-braces — gives us a clear activity log entry
            // and prevents an in-flight read between the delete and
            // FK cascade from briefly seeing dangling category_id.
            ProductPlan::where('category_id', $cat->id)->update(['category_id' => null]);

            $this->logActivity($request, 'plan_category.deleted', $cat->product_id, before: [
                'category_id' => $cat->id,
                'name' => $cat->name,
            ]);
            $cat->delete();
        });

        return back()->with('success', "Category '{$cat->name}' deleted.");
    }

    private function logActivity(
        Request $request,
        string $action,
        int $productId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'product',
            'entity_id' => $productId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
