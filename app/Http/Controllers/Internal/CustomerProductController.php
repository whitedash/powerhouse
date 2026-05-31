<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Mail\ReinstatementNotice;
use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use App\Services\WebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Manual suspend / reinstate of a single customer product. Distinct from
 * CustomerController::suspendProduct (the legacy customer+productId path)
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
