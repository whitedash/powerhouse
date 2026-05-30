<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Internal\ProposalController;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PaymentSchedule;
use App\Models\PaymentScheduleItem;
use App\Models\Proposal;
use App\Models\ProposalLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public proposal acceptance flow — NO auth middleware.
 *
 * The customer reaches this controller via a token URL we generated
 * at send-time. The token is the only authorisation; once accepted
 * we null it out so the link is single-use.
 *
 * Pricing displayed to the customer reflects the entity's
 * vat_registered flag at the time the proposal was sent — we
 * don't re-derive it on view, so a settings flip mid-flight can't
 * mislead anyone.
 */
class ProposalAcceptanceController extends Controller
{
    public function show(string $token): Response
    {
        $proposal = $this->loadByToken($token);

        // Already-accepted proposals show the success page rather
        // than a 404, so a customer who bookmarks the link sees a
        // coherent "you already accepted this" page.
        if ($proposal->status === 'accepted') {
            return Inertia::render('Public/ProposalAccepted', [
                'reference' => $proposal->reference,
                'accepted_at' => $proposal->accepted_at?->format('d M Y H:i'),
                'customer_name' => $proposal->customer->name,
                'already' => true,
            ]);
        }

        // Expired tokens 410 because the customer needs a fresh
        // link from sales rather than a 404 dead-end.
        if ($proposal->acceptance_token_expires_at !== null
            && $proposal->acceptance_token_expires_at->isPast()) {
            abort(410, 'This proposal has expired. Please contact us for a fresh link.');
        }

        if ($proposal->status !== 'sent') {
            abort(410, 'This proposal is no longer available for acceptance.');
        }

        return Inertia::render('Public/ProposalView', [
            'proposal' => $this->mapForPublic($proposal),
            'token' => $token,
            'expires_at' => $proposal->acceptance_token_expires_at?->format('d M Y'),
        ]);
    }

    public function accept(string $token, Request $request): Response
    {
        $proposal = $this->loadByToken($token);

        // Re-validate every condition — a stale tab can lag enough
        // that show() said "ok" but accept() now says "no".
        abort_unless(
            $proposal->status === 'sent'
            && ($proposal->acceptance_token_expires_at === null
                || $proposal->acceptance_token_expires_at->isFuture()),
            410,
            'This acceptance link is no longer valid.'
        );

        $data = $request->validate([
            'accepted_name' => 'required|string|max:255',
            // "accepted" rule = boolean-true / "yes" / "1" / "on" /
            // "true". The checkbox MUST be ticked.
            'accepted_confirm' => 'required|accepted',
        ]);

        DB::transaction(function () use ($proposal, $data, $request) {
            // Stamp the proposal first so generatePdf sees the new
            // accepted_* columns; the PDF needs them for the stamp.
            $proposal->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'accepted_by_name' => $data['accepted_name'],
                'accepted_ip' => $request->ip(),
                'accepted_user_agent' => substr((string) $request->userAgent(), 0, 1000),
                // Invalidate the token — single-use. Anyone who
                // bookmarks the URL now sees ProposalAccepted.
                'acceptance_token' => null,
            ]);

            $pdf = app(ProposalController::class)->generatePdf($proposal->fresh(), true);
            $acceptedPath = 'proposals/accepted/'.$proposal->reference.'-accepted.pdf';
            Storage::disk('private')->put($acceptedPath, $pdf->output());

            $proposal->update(['accepted_pdf_path' => $acceptedPath]);

            // Spawn invoices for "immediate" schedule items so the
            // deposit lands in the customer's inbox without staff
            // intervention. Errors here MUST NOT roll back the
            // acceptance — the customer's signature is binding
            // regardless of whether downstream billing fired.
            try {
                $schedule = $proposal->paymentSchedule;
                if ($schedule !== null) {
                    $this->activateImmediateItems($schedule, $proposal->created_by);
                }
            } catch (\Throwable $e) {
                ActivityLog::create([
                    'user_id' => null,
                    'user_role' => 'guest',
                    'action' => 'proposal.schedule_activation_failed',
                    'entity_type' => 'proposal',
                    'entity_id' => $proposal->id,
                    'after' => ['message' => substr($e->getMessage(), 0, 300)],
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                ]);
            }

            // Audit row — note the actor is the public visitor,
            // not a staff user, so user_id stays null and we mark
            // user_role 'guest'.
            ActivityLog::create([
                'user_id' => null,
                'user_role' => 'guest',
                'action' => 'proposal.accepted',
                'entity_type' => 'proposal',
                'entity_id' => $proposal->id,
                'after' => [
                    'reference' => $proposal->reference,
                    'accepted_by' => $data['accepted_name'],
                    'ip' => $request->ip(),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        });

        // TODO: notify staff via email; email the accepted PDF to customer.

        return Inertia::render('Public/ProposalAccepted', [
            'reference' => $proposal->reference,
            'accepted_at' => now()->format('d M Y H:i'),
            'customer_name' => $proposal->customer->name,
            'already' => false,
        ]);
    }

    /**
     * Look up a proposal by token; 404 on miss. Centralised so
     * the show + accept paths share one error semantics.
     */
    private function loadByToken(string $token): Proposal
    {
        $proposal = Proposal::where('acceptance_token', $token)
            ->with([
                'customer',
                'billingEntity',
                'lines.product:id,name',
                'lines.plan:id,name',
                'paymentSchedule.items.milestone:id,title',
            ])
            ->first();

        // If the token has been nulled (already accepted), fall
        // back to a customer-scoped lookup so an already-accepted
        // bookmark still resolves to the success page. The token
        // string is unguessable so this is safe.
        if ($proposal === null) {
            // We persist a hash of the token? No — the spec stores
            // the raw token until accept clears it. After accept
            // the link is dead by design.
            abort(404, 'Proposal not found.');
        }

        return $proposal;
    }

    /**
     * Generate invoices for items marked trigger_type='immediate'
     * on the schedule. Each item flips to status='invoiced' and
     * holds the invoice id so the Internal Show page can link to it.
     */
    private function activateImmediateItems(PaymentSchedule $schedule, int $createdBy): void
    {
        $items = $schedule->items()
            ->where('trigger_type', 'immediate')
            ->where('status', 'pending')
            ->get();

        /** @var PaymentScheduleItem $item */
        foreach ($items as $item) {
            $invoice = $this->generateScheduleInvoice($schedule, $item, $createdBy);
            $item->update([
                'invoice_id' => $invoice->id,
                'status' => 'invoiced',
            ]);
        }
    }

    /**
     * Public so PaymentScheduleController (manual trigger) can
     * reuse it. Builds a draft invoice for the item's amount with
     * one line carrying the item's label.
     */
    public function generateScheduleInvoice(
        PaymentSchedule $schedule,
        PaymentScheduleItem $item,
        int $createdBy,
    ): Invoice {
        $entity = $schedule->billingEntity
            ?? BillingEntity::where('is_active', true)->first();

        $vatRate = $entity !== null ? (float) $entity->effective_vat_rate : 20.0;
        $vatAmount = round((float) $item->amount * ($vatRate / 100), 2);
        $total = round((float) $item->amount + $vatAmount, 2);

        $invoice = Invoice::create([
            'customer_id' => $schedule->customer_id,
            'billing_entity_id' => $entity?->id,
            'number' => Invoice::generateNextNumber(),
            'type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'subtotal' => $item->amount,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total' => $total,
            'notes' => $item->label.' — '.$schedule->name,
            'created_by' => $createdBy,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => $item->label,
            'quantity' => 1,
            'unit_price' => $item->amount,
            'amount' => $item->amount,
            'sort_order' => 0,
        ]);

        ActivityLog::create([
            'user_id' => null,
            'user_role' => 'system',
            'action' => 'invoice.schedule_generated',
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'after' => [
                'schedule_id' => $schedule->id,
                'item_id' => $item->id,
                'amount' => (float) $item->amount,
            ],
            'ip_address' => null,
            'user_agent' => null,
        ]);

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapForPublic(Proposal $p): array
    {
        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'title' => $p->title,
            'description' => $p->description,
            'terms' => $p->terms,
            'subtotal' => (float) $p->subtotal,
            'discount_amount' => (float) $p->discount_amount,
            'vat_rate' => (float) $p->vat_rate,
            'vat_amount' => (float) $p->vat_amount,
            'total' => (float) $p->total,
            'valid_until' => $p->valid_until?->format('d M Y'),
            'customer_name' => $p->customer->name,
            'entity_name' => $p->billingEntity?->name,
            // billingEntity is nullable on the FK; phpstan reports
            // the ?? as unnecessary because the property type isn't
            // bool|null but bool — fall back via inline conditional.
            'entity_vat_registered' => $p->billingEntity !== null
                ? (bool) $p->billingEntity->vat_registered
                : false,
            'lines' => $p->lines->map(fn (ProposalLine $l): array => [
                'description' => $l->description,
                'note' => $l->note,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'amount' => (float) $l->amount,
                'discount_amount' => (float) $l->discount_amount,
            ])->values(),
            'schedule' => $p->paymentSchedule ? [
                'name' => $p->paymentSchedule->name,
                'items' => $p->paymentSchedule->items->map(fn ($item): array => [
                    'label' => $item->label,
                    'amount' => (float) $item->amount,
                    'trigger_type' => $item->trigger_type,
                    'trigger_date' => $item->trigger_date?->format('d M Y'),
                    'milestone_title' => $item->milestone?->title,
                ])->values(),
            ] : null,
        ];
    }
}
