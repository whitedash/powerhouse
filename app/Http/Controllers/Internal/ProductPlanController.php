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

class ProductPlanController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->prepareDomainFields($this->validatePayload($request, isUpdate: false), null);

        $plan = DB::transaction(function () use ($data, $request) {
            // The validation rules split plan-level fields from
            // initial_* pricing fields. Strip the latter before
            // building the ProductPlan, then use them to seed the
            // first ProductPlanPrice in the same transaction.
            $planData = collect($data)
                ->except(['initial_price', 'initial_interval_count', 'initial_interval_unit'])
                ->all();

            $plan = ProductPlan::create($planData);

            if (isset($data['initial_price'])) {
                ProductPlanPrice::create([
                    'plan_id' => $plan->id,
                    'price' => $data['initial_price'],
                    'interval_count' => $data['initial_interval_count'] ?? 1,
                    'interval_unit' => $data['initial_interval_unit'] ?? 'month',
                    'is_default' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
            }

            $this->logActivity($request, 'product_plan.created', $plan->product_id, after: [
                'plan_id' => $plan->id,
                'name' => $plan->name,
                'category_id' => $plan->category_id,
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

        $data = $this->prepareDomainFields($this->validatePayload($request, isUpdate: true), $plan->id);

        DB::transaction(function () use ($plan, $data, $request) {
            $before = [
                'name' => $plan->name,
                'category_id' => $plan->category_id,
            ];

            // Update only plan-level fields; initial_* never apply on
            // edit — pricing is managed via ProductPlanPriceController.
            $planData = collect($data)
                ->except(['initial_price', 'initial_interval_count', 'initial_interval_unit'])
                ->all();

            $plan->update($planData);

            $this->logActivity($request, 'product_plan.updated', $plan->product_id, $before, [
                'plan_id' => $plan->id,
                'name' => $plan->name,
                'category_id' => $plan->category_id,
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
    /**
     * Normalise the TLD (lowercase, leading dot) and enforce ONE active
     * domain plan per TLD so a domain's TLD → plan match is unambiguous.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareDomainFields(array $data, ?int $ignoreId): array
    {
        $isDomain = (bool) ($data['is_domain'] ?? false);
        if (! $isDomain) {
            return $data;
        }

        // Normalise: lowercase + guaranteed single leading dot.
        if (! empty($data['tld'])) {
            $data['tld'] = '.'.ltrim(strtolower(trim((string) $data['tld'])), '.');
        }

        // Only ACTIVE plans must be unique per TLD (inactive ones may share).
        $isActive = (bool) ($data['is_active'] ?? true);
        if ($isActive && ! empty($data['tld'])) {
            $clash = ProductPlan::where('is_domain', true)
                ->where('is_active', true)
                ->where('tld', $data['tld'])
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'tld' => "An active domain plan already exists for {$data['tld']}. Deactivate it first or reuse that plan.",
                ]);
            }
        }

        // An ACTIVE domain plan must have EXACTLY ONE active price tier — that
        // tier is the renewal duration + price (no separate renewal-term field).
        // Inactive domain plans are exempt (the renewal command only matches
        // active plans). Active-tier count after this save = existing active
        // prices (update) plus the initial tier being created (store, active).
        if ($isActive) {
            $activeTiers = $ignoreId !== null
                ? ProductPlanPrice::where('plan_id', $ignoreId)->where('is_active', true)->count()
                : 0;
            if (! empty($data['initial_price'])) {
                $activeTiers++;
            }

            if ($activeTiers !== 1) {
                throw ValidationException::withMessages([
                    'initial_price' => $activeTiers === 0
                        ? 'A domain plan needs exactly one price tier — set its renewal duration + price.'
                        : 'A domain plan must have exactly one active price tier (its renewal duration + price). Deactivate the extra tier(s) first.',
                ]);
            }
        }

        return $data;
    }

    private function validatePayload(Request $request, bool $isUpdate): array
    {
        // product_id is fixed at create; on update we keep it pinned
        // so a plan can't migrate between products and orphan its
        // existing subscriptions.
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'category_id' => ['nullable', 'integer', 'exists:product_plan_categories,id'],
            'features' => ['nullable', 'array', 'max:10'],
            'features.*' => ['string', 'max:200'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            // Hosting flag — drives the website hosting-plan selector.
            'is_hosting' => ['boolean'],
            // Domain flag + its TLD (required when is_domain). A domain plan's
            // single active price tier is its renewal duration + price.
            'is_domain' => ['boolean'],
            // Leading dot optional — it's normalised in prepareDomainFields().
            'tld' => [
                'nullable', 'string', 'max:20', 'required_if:is_domain,1,true',
                'regex:/^\.?[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/i',
            ],
            'sort_order' => ['integer'],
            // initial_* fields are only honoured on create; the
            // controller drops them on update. Lets staff create a
            // plan and its first price in a single submit.
            'initial_price' => ['nullable', 'numeric', 'min:0'],
            'initial_interval_count' => ['nullable', 'integer', 'min:1', 'max:365'],
            'initial_interval_unit' => ['nullable', 'in:day,week,month,year,one_time'],
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
