<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\ProposalLine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Proposals — quote documents sent to prospects, accepted online
 * via a public token link, optionally converted into contracts.
 *
 * The controller owns three flows:
 *   1. Internal CRUD (index/show/store/update/destroy)
 *   2. Send — generates the unsigned PDF + acceptance token,
 *      flips the proposal to 'sent'.
 *   3. Convert-to-contract — only available on accepted
 *      proposals; copies the post-accept PDF into the contract.
 *
 * The public-side acceptance handling lives in
 * Public/ProposalAcceptanceController (no auth) which calls back
 * into this controller's generatePdf() helper to render the
 * accepted PDF.
 *
 * Authorisation: gates through CustomerPolicy::viewAny like the
 * other internal controllers. A Sprint-2 ProposalPolicy can
 * tighten per-row visibility later if the team grows.
 */
class ProposalController extends Controller
{
    private const STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'expired'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $proposals = Proposal::query()
            ->with([
                'customer:id,name',
                'billingEntity:id,name',
                'createdBy:id,name',
            ])
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->string('search')->toString() !== '', function ($q) use ($request) {
                $s = $request->string('search')->toString();
                $q->where(function ($q2) use ($s) {
                    $q2->where('title', 'like', "%{$s}%")
                        ->orWhere('reference', 'like', "%{$s}%")
                        ->orWhereHas('customer', fn ($q3) => $q3->where('name', 'like', "%{$s}%"));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Proposal $p): array => [
                'id' => $p->id,
                'reference' => $p->reference,
                'title' => $p->title,
                'status' => $p->status,
                'status_label' => $p->status_label,
                'is_expired' => $p->is_expired,
                'customer_id' => $p->customer_id,
                'customer_name' => $p->customer->name,
                'entity_name' => $p->billingEntity?->name,
                'total' => (float) $p->total,
                'valid_until' => $p->valid_until?->format('d M Y'),
                'sent_at' => $p->sent_at?->diffForHumans(),
                'accepted_at' => $p->accepted_at?->format('d M Y'),
                'has_schedule' => $p->paymentSchedule()->exists(),
                'created_at' => $p->created_at?->format('d M Y'),
            ]);

        $summary = [
            'draft' => Proposal::where('status', 'draft')->count(),
            'sent' => Proposal::where('status', 'sent')->count(),
            'accepted' => Proposal::where('status', 'accepted')->count(),
            'total_accepted_value' => (float) Proposal::where('status', 'accepted')->sum('total'),
        ];

        return Inertia::render('Internal/Proposals/Index', [
            'proposals' => $proposals,
            'summary' => $summary,
            'filters' => [
                'status' => $request->string('status')->toString(),
                'search' => $request->string('search')->toString(),
            ],
            'customers' => Customer::whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'billing_entities' => BillingEntity::where('is_active', true)
                ->get(['id', 'name', 'vat_registered', 'default_vat_rate']),
            'products' => Product::where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug']),
            'statuses' => self::STATUSES,
        ]);
    }

    public function show(int $id): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $proposal = Proposal::with([
            'customer.primaryContact',
            'billingEntity',
            'lines.product:id,name,slug',
            'lines.plan:id,name',
            'project:id,title',
            'contract:id,title,status',
            'createdBy:id,name',
            'paymentSchedule.items.milestone:id,title',
            'paymentSchedule.items.invoice:id,number,status,total',
        ])->findOrFail($id);

        return Inertia::render('Internal/Proposals/Show', [
            'proposal' => $this->mapProposal($proposal),
            'milestones' => $proposal->project
                ? $proposal->project->milestones()->get(['id', 'title'])
                : [],
            'billing_entities' => BillingEntity::where('is_active', true)
                ->get(['id', 'name', 'vat_registered', 'default_vat_rate']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'billing_entity_id' => 'nullable|integer|exists:billing_entities,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'terms' => 'nullable|string|max:10000',
            'valid_until' => 'nullable|date|after_or_equal:today',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.note' => 'nullable|string|max:2000',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.product_id' => 'nullable|integer|exists:products,id',
            'lines.*.plan_id' => 'nullable|integer|exists:product_plans,id',
            'lines.*.discount_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'lines.*.discount_value' => 'nullable|numeric|min:0',
        ]);

        $proposal = DB::transaction(function () use ($data, $request) {
            // VAT rate is owned by the entity; we never let the
            // client choose it directly. Fallback to 20% only when
            // no entity is picked (rare, but possible for sales-
            // qualifying drafts before the entity is decided).
            /** @var BillingEntity|null $entity */
            $entity = ! empty($data['billing_entity_id'])
                ? BillingEntity::find($data['billing_entity_id'])
                : null;
            $vatRate = $entity !== null ? $entity->effective_vat_rate : 20.0;

            // Compute line totals server-side. Mirror the discount
            // helper InvoiceController uses; the client could lie
            // about discount_amount and inflate the saving.
            $subtotal = 0.0;
            $discountTotal = 0.0;
            $processedLines = [];

            foreach ($data['lines'] as $i => $line) {
                $gross = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
                $type = $line['discount_type'] ?? null;
                $value = (float) ($line['discount_value'] ?? 0);

                $discAmt = 0.0;
                if ($type !== null && $value > 0) {
                    $discAmt = $type === 'percentage'
                        ? round($gross * ($value / 100), 2)
                        : round($value, 2);
                    $discAmt = min($discAmt, $gross);
                }

                $netAmount = round($gross - $discAmt, 2);
                $subtotal += $netAmount;
                $discountTotal += $discAmt;

                $processedLines[] = [
                    'description' => $line['description'],
                    'note' => $line['note'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $netAmount,
                    'discount_type' => $type,
                    'discount_value' => $value,
                    'discount_amount' => $discAmt,
                    'product_id' => $line['product_id'] ?? null,
                    'plan_id' => $line['plan_id'] ?? null,
                    'sort_order' => $i,
                ];
            }

            $vatAmount = round($subtotal * ($vatRate / 100), 2);
            $total = round($subtotal + $vatAmount, 2);

            $proposal = Proposal::create([
                'customer_id' => $data['customer_id'],
                'billing_entity_id' => $data['billing_entity_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'reference' => Proposal::generateNextReference(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'terms' => $data['terms'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'created_by' => $request->user()->id,
            ]);

            foreach ($processedLines as $line) {
                ProposalLine::create([...$line, 'proposal_id' => $proposal->id]);
            }

            $this->log($request, 'proposal.created', $proposal->id, after: [
                'reference' => $proposal->reference,
                'total' => $total,
            ]);

            return $proposal;
        });

        return redirect('/proposals/'.$proposal->id)
            ->with('success', "Proposal {$proposal->reference} created.");
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $proposal = Proposal::findOrFail($id);

        if ($proposal->status !== 'draft') {
            return back()->with('error', 'Only draft proposals can be deleted.');
        }

        DB::transaction(function () use ($proposal, $request) {
            $ref = $proposal->reference;
            $proposal->delete();
            $this->log($request, 'proposal.deleted', $proposal->id, before: ['reference' => $ref]);
        });

        return redirect('/proposals')->with('success', 'Proposal deleted.');
    }

    /**
     * Move a draft proposal into 'sent' state: generate the
     * unsigned PDF and mint an acceptance token whose TTL
     * matches valid_until (or 30 days if open-ended).
     */
    public function send(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $proposal = Proposal::findOrFail($id);

        if ($proposal->status !== 'draft') {
            return back()->with('error', 'Only draft proposals can be sent.');
        }

        DB::transaction(function () use ($proposal, $request) {
            $token = hash('sha256',
                $proposal->id.$proposal->reference.Str::random(32).config('app.key')
            );

            $pdf = $this->generatePdf($proposal);
            $pdfPath = 'proposals/'.$proposal->reference.'.pdf';
            Storage::disk('private')->put($pdfPath, $pdf->output());

            $proposal->update([
                'status' => 'sent',
                'sent_at' => now(),
                'pdf_path' => $pdfPath,
                'acceptance_token' => $token,
                // Acceptance link expires with the proposal itself.
                // If valid_until is blank, default to 30 days from
                // send time so links don't live forever.
                'acceptance_token_expires_at' => $proposal->valid_until
                    ? $proposal->valid_until->endOfDay()
                    : now()->addDays(30),
            ]);

            $this->log($request, 'proposal.sent', $proposal->id, after: [
                'reference' => $proposal->reference,
                'token_prefix' => substr($token, 0, 8),
            ]);
        });

        // TODO: dispatch Postmark email with acceptance link.

        return back()->with('success', 'Proposal sent. Acceptance link generated.');
    }

    public function downloadPdf(int $id): StreamedResponse|\Illuminate\Http\Response
    {
        Gate::authorize('viewAny', Customer::class);

        $proposal = Proposal::findOrFail($id);

        // Always prefer the on-disk PDF when present — it carries
        // the exact bytes that landed in the customer's inbox.
        // For drafts (no pdf_path yet) we render on the fly.
        if ($proposal->pdf_path !== null) {
            return Storage::disk('private')->download(
                $proposal->pdf_path,
                $proposal->reference.'.pdf',
            );
        }

        return $this->generatePdf($proposal)
            ->stream($proposal->reference.'.pdf');
    }

    public function downloadAcceptedPdf(int $id): StreamedResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $proposal = Proposal::findOrFail($id);

        abort_if($proposal->accepted_pdf_path === null, 404, 'No signed PDF available.');

        return Storage::disk('private')->download(
            $proposal->accepted_pdf_path,
            $proposal->reference.'-accepted.pdf',
        );
    }

    /**
     * Convert an accepted proposal into a Contract. The signed PDF
     * (with the acceptance stamp) is copied into the contracts
     * folder so the contract record is self-contained.
     */
    public function convertToContract(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $proposal = Proposal::findOrFail($id);

        if ($proposal->status !== 'accepted') {
            return back()->with('error', 'Only accepted proposals can be converted to contracts.');
        }

        if ($proposal->contract_id !== null) {
            return redirect('/customers/'.$proposal->customer_id)
                ->with('success', "Proposal {$proposal->reference} already linked to a contract.");
        }

        $contract = DB::transaction(function () use ($proposal, $request) {
            $contract = Contract::create([
                'customer_id' => $proposal->customer_id,
                'created_by' => $request->user()->id,
                'title' => $proposal->title,
                'type' => 'service',
                'status' => 'signed',
                'value' => $proposal->total,
                'signed_at' => $proposal->accepted_at?->toDateString(),
                'description' => 'Generated from proposal '.$proposal->reference,
                'notes' => 'Customer accepted via online acceptance link on '
                    .$proposal->accepted_at?->format('d M Y')
                    .' from IP '.($proposal->accepted_ip ?? 'unknown').'.',
            ]);

            if ($proposal->accepted_pdf_path !== null) {
                $newPath = 'contracts/'.$contract->id.'-'.$proposal->reference.'.pdf';

                try {
                    Storage::disk('private')->copy($proposal->accepted_pdf_path, $newPath);
                    $contract->update([
                        'pdf_path' => $newPath,
                        'file_original_name' => $proposal->reference.'-accepted.pdf',
                    ]);
                } catch (Throwable) {
                    // Copy failure is non-fatal — the contract row
                    // still references the proposal via the notes;
                    // operators can re-upload manually if needed.
                }
            }

            $proposal->update(['contract_id' => $contract->id]);

            $this->log($request, 'proposal.converted_to_contract', $proposal->id, after: [
                'contract_id' => $contract->id,
                'reference' => $proposal->reference,
            ]);

            return $contract;
        });

        return redirect('/customers/'.$proposal->customer_id)
            ->with('success', "Contract created from proposal {$proposal->reference}.");
    }

    /**
     * Build the proposal PDF. Public so the public acceptance
     * controller can invoke it with $withAcceptance=true to
     * render the stamped version after acceptance.
     */
    public function generatePdf(Proposal $proposal, bool $withAcceptance = false): \Barryvdh\DomPDF\PDF
    {
        $proposal->load([
            'lines.product',
            'lines.plan',
            'customer.primaryContact',
            'billingEntity',
            'paymentSchedule.items',
        ]);

        return Pdf::loadView('pdf.proposal', [
            'proposal' => $proposal,
            'entity' => $proposal->billingEntity,
            'logo_data' => $this->resolveLogoData($proposal->billingEntity),
            'with_acceptance' => $withAcceptance,
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'dpi' => 96,
                'defaultFont' => 'Arial',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
            ]);
    }

    /**
     * Mirror of InvoiceController::resolveLogoPath — read the
     * private-disk logo and return a base64 data URL. dompdf's
     * chroot blocks absolute paths outside its own vendor dir,
     * so we have to embed the image.
     */
    private function resolveLogoData(?BillingEntity $entity): ?string
    {
        if ($entity === null) {
            return null;
        }
        $path = $entity->logo_path;
        if (! $path) {
            return null;
        }
        $absolute = Storage::disk('private')->path($path);
        if (! file_exists($absolute)) {
            return null;
        }
        $mime = mime_content_type($absolute) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($absolute));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProposal(Proposal $p): array
    {
        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'title' => $p->title,
            'description' => $p->description,
            'terms' => $p->terms,
            'status' => $p->status,
            'status_label' => $p->status_label,
            'is_expired' => $p->is_expired,
            'subtotal' => (float) $p->subtotal,
            'discount_amount' => (float) $p->discount_amount,
            'vat_rate' => (float) $p->vat_rate,
            'vat_amount' => (float) $p->vat_amount,
            'total' => (float) $p->total,
            'valid_until' => $p->valid_until?->format('d M Y'),
            'valid_until_raw' => $p->valid_until?->toDateString(),
            'sent_at' => $p->sent_at?->toIso8601String(),
            'sent_at_display' => $p->sent_at?->diffForHumans(),
            'accepted_at' => $p->accepted_at?->format('d M Y H:i'),
            'accepted_by_name' => $p->accepted_by_name,
            'accepted_ip' => $p->accepted_ip,
            'acceptance_token' => $p->acceptance_token,
            'has_pdf' => $p->pdf_path !== null,
            'has_accepted_pdf' => $p->accepted_pdf_path !== null,
            'notes' => $p->notes,
            'created_at' => $p->created_at?->format('d M Y'),
            'customer' => [
                'id' => $p->customer->id,
                'name' => $p->customer->name,
                'city' => $p->customer->city,
                'primary_contact' => $p->customer->primaryContact?->name,
            ],
            'billing_entity' => $p->billingEntity ? [
                'id' => $p->billingEntity->id,
                'name' => $p->billingEntity->name,
                'vat_registered' => $p->billingEntity->vat_registered,
                'default_vat_rate' => (float) $p->billingEntity->default_vat_rate,
            ] : null,
            'project' => $p->project ? ['id' => $p->project->id, 'title' => $p->project->title] : null,
            'contract' => $p->contract ? [
                'id' => $p->contract->id,
                'title' => $p->contract->title,
                'status' => $p->contract->status,
            ] : null,
            // created_by is NOT NULL so createdBy always resolves;
            // drop the ternary to keep phpstan quiet.
            'created_by' => ['id' => $p->createdBy->id, 'name' => $p->createdBy->name],
            'lines' => $p->lines->map(fn (ProposalLine $l): array => [
                'id' => $l->id,
                'description' => $l->description,
                'note' => $l->note,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'amount' => (float) $l->amount,
                'discount_type' => $l->discount_type,
                'discount_value' => (float) $l->discount_value,
                'discount_amount' => (float) $l->discount_amount,
                'product_name' => $l->product?->name,
                'plan_name' => $l->plan?->name,
            ])->values(),
            'payment_schedule' => $p->paymentSchedule ? [
                'id' => $p->paymentSchedule->id,
                'name' => $p->paymentSchedule->name,
                'total' => (float) $p->paymentSchedule->total,
                'completion_percentage' => $p->paymentSchedule->completion_percentage,
                'items' => $p->paymentSchedule->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'label' => $item->label,
                    'percentage' => $item->percentage !== null ? (float) $item->percentage : null,
                    'amount' => (float) $item->amount,
                    'trigger_type' => $item->trigger_type,
                    'trigger_date' => $item->trigger_date?->format('d M Y'),
                    'milestone' => $item->milestone ? [
                        'id' => $item->milestone->id,
                        'title' => $item->milestone->title,
                    ] : null,
                    'invoice' => $item->invoice ? [
                        'id' => $item->invoice->id,
                        'number' => $item->invoice->number,
                        'status' => $item->invoice->status,
                        'total' => (float) $item->invoice->total,
                    ] : null,
                    'status' => $item->status,
                    'is_triggerable' => $item->is_triggerable,
                ])->values(),
            ] : null,
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
            'entity_type' => 'proposal',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
