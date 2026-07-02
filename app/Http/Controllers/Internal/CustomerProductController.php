<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Mail\ReinstatementNotice;
use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Services\WebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manual suspend / reinstate of a single customer product. Distinct from
 * CompanyController::suspendProduct (the legacy customer+productId path)
 * — these act on a CustomerProduct id, capture a reason, fire the product
 * webhook, and record who acted (suspended_by / reinstated_by) so the
 * audit trail separates staff actions from the auto-suspension sweep.
 */
class CustomerProductController extends Controller
{
    public function suspend(int $id, Request $request, WebhookDispatcher $dispatcher): RedirectResponse
    {
        $cp = CustomerProduct::with(['product', 'customer'])->findOrFail($id);
        Gate::authorize('update', $cp->customer);

        $data = $request->validate([
            'reason' => ['required', Rule::in(['non_payment', 'manual', 'fraud', 'other'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($cp->status === 'suspended') {
            return back()->with('error', 'This subscription is already suspended.');
        }

        DB::transaction(function () use ($cp, $data, $request, $dispatcher): void {
            $before = ['status' => $cp->status];

            $cp->update([
                'status' => 'suspended',
                'suspension_reason' => $data['reason'],
                'suspended_at' => now(),
                'suspended_by' => $request->user()->id,
            ]);

            $dispatcher->dispatchSuspension($cp);

            $this->log($request, 'customer_product.suspended', $cp->customer_id, $before, [
                'customer_product_id' => $cp->id,
                'product' => $cp->product?->name,
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
            ]);
        });

        Cache::forget('dash.mrr');
        Cache::forget('dash.total_customers');

        return back()->with('success', 'Product suspended.');
    }

    public function reinstate(int $id, Request $request, WebhookDispatcher $dispatcher): RedirectResponse
    {
        $cp = CustomerProduct::with(['product', 'customer.primaryContact'])->findOrFail($id);
        Gate::authorize('update', $cp->customer);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($cp->status !== 'suspended') {
            return back()->with('error', 'Only a suspended subscription can be reinstated.');
        }

        DB::transaction(function () use ($cp, $data, $request, $dispatcher): void {
            $before = ['status' => $cp->status];

            $cp->update([
                'status' => 'active',
                'reinstated_at' => now(),
                'reinstated_by' => $request->user()->id,
                'reinstatement_reason' => $data['reason'] ?? null,
                'suspension_reason' => null,
                'suspended_at' => null,
                'suspended_by' => null,
            ]);

            $dispatcher->dispatchReinstatement($cp);

            $this->log($request, 'customer_product.reinstated', $cp->customer_id, $before, [
                'customer_product_id' => $cp->id,
                'product' => $cp->product?->name,
                'reason' => $data['reason'] ?? null,
            ]);
        });

        Cache::forget('dash.mrr');
        Cache::forget('dash.total_customers');

        // Let the customer know access is back (outside the transaction).
        $contactEmail = $cp->customer?->primaryContact?->email;
        if ($contactEmail !== null) {
            Mail::to($contactEmail)->send(new ReinstatementNotice($cp, $cp->customer));
        }

        return back()->with('success', 'Product reinstated.');
    }

    /**
     * Edit a single service (CustomerProduct): plan, price, active-since,
     * renewal date, status, label. Mirrors the enable-product resolution
     * (plan_price wins for price/interval, plan_id for the plan name).
     * Status here is limited to active/trial — suspend/reinstate keep their
     * dedicated, side-effecting flows, so a suspended/cancelled service must
     * be reinstated before it can be edited.
     */
    public function update(int $id, Request $request): RedirectResponse
    {
        $cp = CustomerProduct::with(['product', 'customer'])->findOrFail($id);
        Gate::authorize('update', $cp->customer);

        if (in_array($cp->status, ['suspended', 'cancelled'], true)) {
            return back()->with('error', 'Reinstate this service before editing it.');
        }

        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:product_plans,id'],
            'plan_price_id' => ['nullable', 'integer', 'exists:product_plan_prices,id'],
            'plan' => ['nullable', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
            'price_monthly' => ['nullable', 'numeric', 'min:0'],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:365'],
            'interval_unit' => ['nullable', 'in:day,week,month,year,one_time'],
            'started_at' => ['nullable', 'date'],
            'next_billing_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,trial'],
            'trial_ends_at' => ['nullable', 'date', 'required_if:status,trial'],
        ]);

        // Resolution order mirrors enableProduct: plan_price_id is canonical for
        // price + interval; plan_id (or the price's plan) for the plan name; a
        // free-text fallback for custom arrangements.
        $planPrice = ! empty($data['plan_price_id']) ? ProductPlanPrice::find($data['plan_price_id']) : null;
        $plan = ! empty($data['plan_id'])
            ? ProductPlan::find($data['plan_id'])
            : ($planPrice ? ProductPlan::find($planPrice->plan_id) : null);

        // Assets are never services — a domain/hosting plan can't be set here.
        if ($plan && ($plan->is_domain || $plan->is_hosting)) {
            throw ValidationException::withMessages([
                'plan_id' => 'That plan is an asset (domain/hosting), not a service.',
            ]);
        }

        $planName = $plan ? $plan->name : ($data['plan'] ?? null);
        $price = $planPrice ? (float) $planPrice->price : ($data['price_monthly'] ?? null);
        $intervalCount = $planPrice ? $planPrice->interval_count : (int) ($data['interval_count'] ?? 1);
        $intervalUnit = $planPrice ? $planPrice->interval_unit : ($data['interval_unit'] ?? 'month');

        DB::transaction(function () use ($cp, $data, $request, $plan, $planPrice, $planName, $price, $intervalCount, $intervalUnit): void {
            $before = $cp->only(['plan_id', 'plan_price_id', 'plan', 'price_monthly', 'status', 'started_at', 'next_billing_date']);

            $cp->update([
                'plan_id' => $plan?->id,
                'plan_price_id' => $planPrice?->id,
                'plan' => $planName,
                'label' => $data['label'] ?? null,
                'price_monthly' => $price,
                'interval_count' => $intervalCount,
                'interval_unit' => $intervalUnit,
                'started_at' => $data['started_at'] ?? null,
                'next_billing_date' => $data['next_billing_date'] ?? null,
                'status' => $data['status'],
                'trial_ends_at' => $data['status'] === 'trial' ? ($data['trial_ends_at'] ?? null) : null,
            ]);

            $this->log($request, 'customer_product.updated', $cp->customer_id, $before, [
                'customer_product_id' => $cp->id,
                'product' => $cp->product?->name,
                'plan' => $planName,
                'price_monthly' => $price,
                'status' => $data['status'],
            ]);
        });

        Cache::forget('dash.mrr');
        Cache::forget('dash.total_customers');

        return back()->with('success', 'Service updated.');
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Request $request, string $action, int $customerId, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => $action,
            'entity_type' => 'customer',
            'entity_id' => $customerId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
