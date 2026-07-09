<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductPlanPriceController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:product_plans,id'],
            'price' => ['required', 'numeric', 'min:0'],
            // Optional one-off setup fee — recurring prices only; a
            // one_time price charging a "fee then recurring" is nonsense,
            // so prohibit it there (validation-layer, per SCHEMA.md).
            'setup_fee' => ['nullable', 'numeric', 'min:0', 'prohibited_if:interval_unit,one_time'],
            'interval_count' => ['required', 'integer', 'min:1', 'max:365'],
            'interval_unit' => ['required', 'in:day,week,month,year,one_time'],
            'stripe_price_id' => ['nullable', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
            ...$this->introRules($request->filled('plan_id') ? (int) $request->input('plan_id') : null, null),
        ], ['setup_fee.prohibited_if' => 'A one-time price cannot carry a setup fee — setup fees apply to recurring prices only.']);

        // A domain plan may carry only ONE active price tier.
        $this->assertDomainSingleTier(
            (int) $data['plan_id'],
            null,
            ! $request->has('is_active') || $request->boolean('is_active'),
        );

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
            'setup_fee' => ['nullable', 'numeric', 'min:0', 'prohibited_if:interval_unit,one_time'],
            'interval_count' => ['required', 'integer', 'min:1', 'max:365'],
            'interval_unit' => ['required', 'in:day,week,month,year,one_time'],
            'stripe_price_id' => ['nullable', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
            ...$this->introRules($price->plan_id, $price->id),
        ], ['setup_fee.prohibited_if' => 'A one-time price cannot carry a setup fee — setup fees apply to recurring prices only.']);

        // Activating this tier must not give a domain plan a second active one.
        $this->assertDomainSingleTier(
            $price->plan_id,
            $price->id,
            ! $request->has('is_active') || $request->boolean('is_active'),
        );

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

    /**
     * Validation for the intro-price schedule (Plans widget). The two fields
     * are set together or not at all; when set, the price becomes an intro
     * price that swaps to intro_swap_price_id after intro_duration_days.
     *
     * Invariants (validation-layer, like the setup_fee / one_time rule):
     *  - both-or-neither (mutual required_with);
     *  - the target must exist, live in the SAME plan, not be the price itself,
     *    not be one_time (nothing recurring to swap to), and not itself be an
     *    intro price (no chains);
     *  - mutually exclusive with setup_fee (two different recurring models).
     *
     * @return array<string, array<int, mixed>>
     */
    private function introRules(?int $planId, ?int $selfId): array
    {
        return [
            'intro_duration_days' => ['nullable', 'integer', 'min:1', 'max:3650', 'required_with:intro_swap_price_id'],
            'intro_swap_price_id' => [
                'nullable', 'integer', 'required_with:intro_duration_days',
                function (string $attribute, mixed $value, \Closure $fail) use ($planId, $selfId): void {
                    if ($value === null) {
                        return;
                    }
                    if (request()->filled('setup_fee')) {
                        $fail('An intro price cannot also carry a setup fee — they are different recurring models.');

                        return;
                    }
                    if ($selfId !== null && (int) $value === $selfId) {
                        $fail('An intro price cannot transition to itself.');

                        return;
                    }
                    $target = ProductPlanPrice::find($value);
                    if ($target === null) {
                        $fail('The selected swap-target price does not exist.');

                        return;
                    }
                    if ($planId !== null && $target->plan_id !== $planId) {
                        $fail('The swap-target price must belong to the same plan.');

                        return;
                    }
                    if ($target->interval_unit === 'one_time') {
                        $fail('The swap-target price must be a recurring price, not one-time.');

                        return;
                    }
                    if ($target->isIntroPrice()) {
                        $fail('The swap-target price cannot itself be an intro price.');
                    }
                },
            ],
        ];
    }

    /**
     * A domain plan must have exactly one active price tier (its renewal
     * duration + price). Block adding/activating a second active tier so the
     * renewal command's match stays unambiguous.
     */
    private function assertDomainSingleTier(int $planId, ?int $ignoreId, bool $willBeActive): void
    {
        if (! $willBeActive) {
            return;
        }

        $plan = ProductPlan::find($planId);
        if ($plan === null || ! $plan->is_domain) {
            return;
        }

        $otherActive = ProductPlanPrice::where('plan_id', $planId)
            ->where('is_active', true)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($otherActive) {
            throw ValidationException::withMessages([
                'price' => 'A domain plan can have only one active price tier (its renewal duration + price). Deactivate the existing tier first.',
            ]);
        }
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
