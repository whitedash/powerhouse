<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use App\Models\ProductPlanPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductPlanPriceController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:product_plans,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'interval_count' => ['required', 'integer', 'min:1', 'max:365'],
            'interval_unit' => ['required', 'in:day,week,month,year,one_time'],
            'stripe_price_id' => ['nullable', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        // is_default is mutually exclusive across a plan's prices —
        // clear the rest before insert so we never end up with two.
        $price = DB::transaction(function () use ($data, $request) {
            if ($request->boolean('is_default')) {
                ProductPlanPrice::where('plan_id', $data['plan_id'])->update(['is_default' => false]);
            }

            $price = ProductPlanPrice::create($data);

            $this->logActivity($request, 'plan_price.created', $price->plan_id, after: [
                'price_id' => $price->id,
                'price' => $price->price,
                'interval' => $price->interval_label,
                'is_default' => $price->is_default,
            ]);

            return $price;
        });

        return back()->with('success', "Pricing option {$price->display_label} added.");
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $price = ProductPlanPrice::findOrFail($id);

        // plan_id is fixed: a price can't migrate between plans (would
        // orphan any subs that picked it).
        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0'],
            'interval_count' => ['required', 'integer', 'min:1', 'max:365'],
            'interval_unit' => ['required', 'in:day,week,month,year,one_time'],
            'stripe_price_id' => ['nullable', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($price, $data, $request) {
            // Promoting to default clears any other default on the
            // same plan. Demoting is a free no-op.
            if (($data['is_default'] ?? false) && ! $price->is_default) {
                ProductPlanPrice::where('plan_id', $price->plan_id)
                    ->where('id', '!=', $price->id)
                    ->update(['is_default' => false]);
            }

            $before = [
                'price' => $price->price,
                'interval' => $price->interval_label,
                'is_default' => $price->is_default,
            ];
            $price->update($data);

            $this->logActivity($request, 'plan_price.updated', $price->plan_id, $before, [
                'price_id' => $price->id,
                'price' => $price->price,
                'interval' => $price->interval_label,
                'is_default' => $price->is_default,
            ]);
        });

        return back()->with('success', 'Pricing option updated.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $price = ProductPlanPrice::findOrFail($id);

        $active = CustomerProduct::where('plan_price_id', $id)
            ->whereIn('status', ['active', 'trial'])
            ->count();

        if ($active > 0) {
            $noun = $active === 1 ? 'subscription uses' : 'subscriptions use';

            return back()->with('error', "Cannot delete — {$active} active {$noun} this price.");
        }

        // The plan needs at least one price to be sellable; refuse to
        // delete the last one so the plan can't get stranded.
        $total = ProductPlanPrice::where('plan_id', $price->plan_id)->count();
        if ($total <= 1) {
            return back()->with('error', 'Cannot delete the only price for a plan. Add another price first.');
        }

        DB::transaction(function () use ($price, $request) {
            $this->logActivity($request, 'plan_price.deleted', $price->plan_id, before: [
                'price_id' => $price->id,
                'price' => $price->price,
            ]);
            $price->delete();
        });

        return back()->with('success', 'Pricing option deleted.');
    }

    private function logActivity(
        Request $request,
        string $action,
        int $planId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'product_plan',
            'entity_id' => $planId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
