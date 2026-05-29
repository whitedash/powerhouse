<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use App\Models\ProductPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductPlanController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request, isUpdate: false);

        $plan = DB::transaction(function () use ($data, $request) {
            $plan = ProductPlan::create($data);

            $this->logActivity($request, 'product_plan.created', $plan->product_id, after: [
                'plan_id' => $plan->id,
                'name' => $plan->name,
                'price' => $plan->price,
                'interval' => $plan->interval_label,
            ]);

            return $plan;
        });

        return back()->with(
            'success',
            "Plan '{$plan->name}' added to {$plan->product?->name}.",
        );
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $plan = ProductPlan::findOrFail($id);

        $data = $this->validatePayload($request, isUpdate: true);

        DB::transaction(function () use ($plan, $data, $request) {
            $before = [
                'name' => $plan->name,
                'price' => $plan->price,
                'interval_count' => $plan->interval_count,
                'interval_unit' => $plan->interval_unit,
            ];

            $plan->update($data);

            $this->logActivity($request, 'product_plan.updated', $plan->product_id, $before, [
                'plan_id' => $plan->id,
                'name' => $plan->name,
                'price' => $plan->price,
                'interval' => $plan->interval_label,
            ]);
        });

        return back()->with('success', "Plan '{$plan->name}' updated.");
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $plan = ProductPlan::findOrFail($id);

        // Block deletion if anyone is currently on this plan — silently
        // losing the FK would leave their subscription pointing at a
        // ghost row. They have to be migrated to another plan first.
        $active = CustomerProduct::where('plan_id', $id)
            ->whereIn('status', ['active', 'trial'])
            ->count();

        if ($active > 0) {
            $noun = $active === 1 ? 'subscription uses' : 'subscriptions use';

            return back()->with(
                'error',
                "Cannot delete '{$plan->name}' — {$active} active {$noun} this plan. Move them to another plan first.",
            );
        }

        DB::transaction(function () use ($plan, $request) {
            $this->logActivity($request, 'product_plan.deleted', $plan->product_id, before: [
                'plan_id' => $plan->id,
                'name' => $plan->name,
            ]);

            $plan->delete();
        });

        return back()->with('success', "Plan '{$plan->name}' deleted.");
    }

    public function toggleActive(int $id, Request $request): RedirectResponse
    {
        $plan = ProductPlan::findOrFail($id);

        $newState = ! $plan->is_active;

        DB::transaction(function () use ($plan, $newState, $request) {
            $plan->update(['is_active' => $newState]);

            $this->logActivity($request, 'product_plan.toggled', $plan->product_id, after: [
                'plan_id' => $plan->id,
                'is_active' => $newState,
            ]);
        });

        $verb = $newState ? 'activated' : 'deactivated';

        return back()->with('success', "Plan '{$plan->name}' {$verb}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $isUpdate): array
    {
        // product_id is fixed at create; on update we keep it pinned
        // so a plan can't migrate between products and orphan its
        // existing subscriptions.
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0'],
            'interval_count' => ['required', 'integer', 'min:1', 'max:365'],
            'interval_unit' => ['required', 'in:day,week,month,year,one_time'],
            'stripe_price_id' => ['nullable', 'string', 'max:100'],
            'features' => ['nullable', 'array', 'max:10'],
            'features.*' => ['string', 'max:200'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'sort_order' => ['integer'],
        ];

        if (! $isUpdate) {
            $rules['product_id'] = ['required', 'integer', 'exists:products,id'];
        }

        return $request->validate($rules);
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
