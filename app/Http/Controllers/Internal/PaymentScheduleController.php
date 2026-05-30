<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\ProposalAcceptanceController;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PaymentSchedule;
use App\Models\PaymentScheduleItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Payment schedules — staff-side endpoints. Two operations:
 *
 *  - store: attach a schedule to a proposal (and optionally the
 *    underlying project) at any time before / after the proposal
 *    is sent. We deliberately allow editing on accepted proposals
 *    too, with the caveat that future-sprint we should snapshot
 *    the schedule into the contract.
 *
 *  - triggerItem: spawn a draft invoice for a 'manual' item the
 *    operator decides is now due. Reuses
 *    ProposalAcceptanceController::generateScheduleInvoice so
 *    automatic + manual triggers produce identical invoices.
 */
class PaymentScheduleController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'proposal_id' => 'nullable|integer|exists:proposals,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'billing_entity_id' => 'nullable|integer|exists:billing_entities,id',
            'total' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.label' => 'required|string|max:255',
            'items.*.percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.amount' => 'required|numeric|min:0',
            'items.*.trigger_type' => ['required', Rule::in(['immediate', 'on_date', 'on_milestone', 'manual'])],
            'items.*.trigger_date' => 'nullable|date',
            'items.*.milestone_id' => 'nullable|integer|exists:milestones,id',
        ]);

        DB::transaction(function () use ($data, $request) {
            // One schedule per proposal — the relation on Proposal
            // is HasOne. Replace any existing schedule rather than
            // erroring; the operator's intent is "this is the new
            // version".
            if (! empty($data['proposal_id'])) {
                PaymentSchedule::where('proposal_id', $data['proposal_id'])->delete();
            }

            $schedule = PaymentSchedule::create([
                'name' => $data['name'],
                'proposal_id' => $data['proposal_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'billing_entity_id' => $data['billing_entity_id'] ?? null,
                'total' => $data['total'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['items'] as $i => $item) {
                PaymentScheduleItem::create([
                    'schedule_id' => $schedule->id,
                    'label' => $item['label'],
                    'percentage' => $item['percentage'] ?? null,
                    'amount' => $item['amount'],
                    'trigger_type' => $item['trigger_type'],
                    'trigger_date' => $item['trigger_date'] ?? null,
                    'milestone_id' => $item['milestone_id'] ?? null,
                    'sort_order' => $i,
                ]);
            }

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'user_role' => $request->user()->role,
                'action' => 'payment_schedule.created',
                'entity_type' => 'payment_schedule',
                'entity_id' => $schedule->id,
                'after' => [
                    'name' => $schedule->name,
                    'item_count' => count($data['items']),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        });

        return back()->with('success', 'Payment schedule saved.');
    }

    public function triggerItem(int $itemId, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        /** @var PaymentScheduleItem $item */
        $item = PaymentScheduleItem::with('schedule')->findOrFail($itemId);

        if ($item->status !== 'pending') {
            return back()->with('error', 'This item has already been invoiced.');
        }

        $invoice = DB::transaction(function () use ($item, $request) {
            $invoice = app(ProposalAcceptanceController::class)
                ->generateScheduleInvoice($item->schedule, $item, $request->user()->id);

            $item->update([
                'invoice_id' => $invoice->id,
                'status' => 'invoiced',
            ]);

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'user_role' => $request->user()->role,
                'action' => 'payment_schedule.manually_invoiced',
                'entity_type' => 'payment_schedule',
                'entity_id' => $item->schedule_id,
                'after' => [
                    'item_id' => $item->id,
                    'invoice_id' => $invoice->id,
                    'amount' => (float) $item->amount,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);

            return $invoice;
        });

        return redirect('/invoices/'.$invoice->id)
            ->with('success', "Invoice {$invoice->number} created from payment schedule.");
    }
}
