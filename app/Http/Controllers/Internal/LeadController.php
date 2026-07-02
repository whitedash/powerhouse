<?php

namespace App\Http\Controllers\Internal;

use App\Enums\PersonRole;
use App\Enums\ReferralStatus;
use App\Enums\ScopeArea;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportLeadsRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\DealRegistrationService;
use App\Services\FileUploadService;
use App\Services\LeadImportService;
use App\Services\NotificationService;
use App\Services\PersonService;
use App\Support\ScopeEnforcer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leads — pipeline kanban + conversion to customer.
 *
 * Three rules that fall out of the lead/customer split:
 *
 *  1) index() must never return converted leads. The
 *     whereNull('customer_id') filter is the single source of
 *     truth for "this row is still in the pipeline".
 *
 *  2) convert() is the only way to mint a customer from a lead.
 *     It mirrors CompanyController::store as closely as
 *     possible: Company::create + primary Contact + carry
 *     across acquisition_channel + channel_detail. The
 *     existing customers schema doesn't carry email/phone
 *     directly — those live on contacts — so the conversion
 *     creates a contact for the lead's email/phone pair.
 *
 *  3) destroy() refuses on converted leads. Once the lead has
 *     a customer_id it's part of the audit chain; deleting
 *     it would silently break the customers.lead_origin link.
 */
class LeadController extends Controller
{
    private const STATUSES = [
        'new', 'contacted', 'qualified', 'proposal',
        'negotiation', 'won', 'lost', 'unresponsive',
    ];

    // 'import' is set by LeadImportService, not hand-picked on the create
    // form (the UI filters it out of the channel pills) — but it MUST stay
    // in this list: validateRow gates update() too, and editing an
    // imported lead re-submits source='import'.
    private const SOURCES = [
        'manual', 'landing_page', 'facebook', 'google',
        'referral', 'email', 'phone', 'event',
        'word_of_mouth', 'other', 'import',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Company::class);
        // None scope (phase 3b-iii) is walled off Leads entirely.
        $this->authorizeScopeSection(ScopeArea::Leads);

        $userId = $request->user()->id;

        // Mandatory scope filter, ALWAYS-ON (phase 3b-iii): All → whole
        // pipeline; Assigned → only the user's leads (the existing
        // assigned_to_me toggle below becomes redundant but harmless under
        // Assigned, and still works under All); None → never reaches here.
        $leads = $this->scopeList(Lead::query(), ScopeArea::Leads)
            ->with([
                'assignedTo:id,name,avatar_colour',
                'createdBy:id,name',
                'referrer.user:id,name',
            ])
            ->whereNull('customer_id')
            // Deal-registration leads only enter the sales kanban once
            // approved; pending_review / rejected / expired are handled in
            // the referral-review queue, not here. NULL = house lead.
            ->where(fn ($q) => $q->whereNull('referral_status')->orWhere('referral_status', 'approved'))
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('assigned_to_me'), fn ($q) => $q->where('assigned_to', $userId))
            ->when($request->string('source')->toString() !== '', fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->string('search')->toString() !== '', function ($q) use ($request) {
                $s = $request->string('search')->toString();
                $q->where(function ($q2) use ($s) {
                    $q2->where('first_name', 'like', "%{$s}%")
                        ->orWhere('last_name', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%")
                        ->orWhere('company', 'like', "%{$s}%");
                });
            })
            ->orderByRaw("CASE status
                WHEN 'new'          THEN 1
                WHEN 'contacted'    THEN 2
                WHEN 'qualified'    THEN 3
                WHEN 'proposal'     THEN 4
                WHEN 'negotiation'  THEN 5
                WHEN 'won'          THEN 6
                WHEN 'lost'         THEN 7
                WHEN 'unresponsive' THEN 8
            END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Lead $l): array => $this->mapLead($l))
            ->values();

        // KPIs describe the working pipeline, so they exclude unreviewed /
        // rejected / expired registrations the same way the kanban does.
        $pipeline = fn ($q) => $q->whereNull('customer_id')
            ->where(fn ($q2) => $q2->whereNull('referral_status')->orWhere('referral_status', 'approved'));

        // KPI chips follow the user's EFFECTIVE scope (phase 3b-iv follow-up):
        // All/super_admin → whole-pipeline totals (scopeList is a no-op);
        // Assigned → "my pipeline" (the same assigned_to filter the kanban
        // uses). $scoped() yields a fresh scoped base per chip. This keeps the
        // chips consistent with the scoped list instead of showing team totals
        // to a scoped rep.
        $scoped = fn () => $this->scopeList(Lead::query(), ScopeArea::Leads);

        $summary = [
            'total' => $scoped()->where($pipeline)->count(),
            'new' => $scoped()->where($pipeline)->where('status', 'new')->count(),
            'qualified_plus' => $scoped()->where($pipeline)
                ->whereIn('status', ['qualified', 'proposal', 'negotiation'])->count(),
            'total_pipeline_value' => (float) $scoped()->where($pipeline)
                ->whereNotIn('status', ['lost', 'won'])
                ->sum('estimated_value'),
            'converted_this_month' => $scoped()->whereNotNull('customer_id')
                ->where('converted_at', '>=', now()->startOfMonth())
                ->count(),
            'pending_review' => $scoped()->whereNull('customer_id')
                ->where('referral_status', 'pending_review')->count(),
        ];

        // Referral-review queue — pending_review deals awaiting approval. Also
        // scoped (phase 3b-iii): it lists lead identities, so an Assigned user
        // must not see deals not assigned to them. Pending deals are normally
        // unassigned, so the queue empties under Assigned — consistent with the
        // per-item approve/reject gate (review is effectively an All-scope job).
        $referralReview = $this->scopeList(Lead::query(), ScopeArea::Leads)
            ->whereNull('customer_id')
            ->where('referral_status', ReferralStatus::PendingReview->value)
            ->with('referrer.user:id,name')
            ->orderBy('registered_at')
            ->get()
            ->map(fn (Lead $l): array => $this->mapReferralReview($l))
            ->values();

        $staff = User::whereIn('role', ['super_admin', 'staff'])
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_colour']);

        return Inertia::render('Internal/Leads/Index', [
            'leads' => $leads,
            'summary' => $summary,
            'referral_review' => $referralReview,
            'staff' => $staff,
            'statuses' => self::STATUSES,
            'sources' => self::SOURCES,
            'import_max_rows' => LeadImportService::MAX_ROWS,
            'filters' => [
                'status' => $request->string('status')->toString(),
                'source' => $request->string('source')->toString(),
                'search' => $request->string('search')->toString(),
                'assigned_to_me' => $request->boolean('assigned_to_me'),
            ],
        ]);
    }

    public function show(int $id): Response
    {
        Gate::authorize('viewAny', Company::class);

        $lead = Lead::with([
            'assignedTo:id,name,avatar_colour',
            'createdBy:id,name',
            'customer:id,name',
            // Lead activities ride the Tasks scope: Assigned → own only; None
            // → none; All/super_admin → all (phase 3b-ii — eager load isn't
            // covered by Gate::before, so constrainRelation handles the bypass).
            'tasks' => function ($q) {
                $q->with('assignedTo:id,name,avatar_colour')
                    ->orderByRaw('due_at IS NULL, due_at ASC');
                ScopeEnforcer::constrainRelation($q, auth()->user(), ScopeArea::Tasks);
            },
            'notesThread' => fn ($q) => $q->with('createdBy:id,name,avatar_colour')
                ->orderByDesc('created_at'),
        ])->findOrFail($id);

        // Scope (phase 3b-iii): a non-assigned lead is invisible by direct ID
        // under Assigned (and NULL-assigned counts as non-assigned). None →
        // 403; All/super_admin → allowed. The viewAny(Company) gate above does
        // NOT check assignment, so without this any staffer could open any lead.
        $this->authorizeScopeItem(ScopeArea::Leads, $lead);

        $staff = User::whereIn('role', ['super_admin', 'staff'])
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_colour']);

        return Inertia::render('Internal/Leads/Show', [
            'lead' => $this->mapLeadDetail($lead),
            'staff' => $staff,
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Company::class);
        // None scope (phase 3b-iii): no creating a lead it could never see.
        $this->authorizeScopeSection(ScopeArea::Leads);

        $data = $this->validateRow($request);

        $lead = DB::transaction(function () use ($data, $request) {
            $lead = Lead::create([
                ...$data,
                'created_by' => $request->user()->id,
            ]);

            $this->log($request, 'lead.created', $lead->id, after: [
                'name' => $lead->name,
                'source' => $lead->source,
            ]);

            return $lead;
        });

        app(NotificationService::class)->notifyLeadAssigned($lead, $request->user());

        return back()->with('success', 'Lead added.');
    }

    /**
     * CSV import — bounded synchronous run, all orchestration in
     * LeadImportService. The file goes through FileUploadService's
     * 'import' context (mime/size gate), is parsed, then deleted:
     * activity_log carries the audit trail and there is no imports
     * table to reference a kept file from.
     */
    public function import(ImportLeadsRequest $request, FileUploadService $uploads, LeadImportService $importer): RedirectResponse
    {
        Gate::authorize('viewAny', Company::class);
        // None scope (phase 3b-iii): no importing leads it could never see.
        $this->authorizeScopeSection(ScopeArea::Leads);

        $file = $request->file('file');
        $path = $uploads->store($file, 'import');

        try {
            $summary = $importer->import(
                Storage::disk('private')->path($path),
                $file->getClientOriginalName(),
                $request->user(),
            );
        } finally {
            $uploads->delete($path);
        }

        $problems = count($summary['skipped']) + count($summary['flagged'])
            + count($summary['failed']) + $summary['example_ignored'];
        $headline = sprintf(
            '%d lead%s imported (%d row%s in file).',
            $summary['created'],
            $summary['created'] === 1 ? '' : 's',
            $summary['total_rows'],
            $summary['total_rows'] === 1 ? '' : 's',
        );

        if ($problems === 0) {
            return back()->with('success', $headline);
        }

        $detail = sprintf(
            ' %d skipped as duplicates, %d flagged, %d failed',
            count($summary['skipped']),
            count($summary['flagged']),
            count($summary['failed']),
        );
        if ($summary['example_ignored'] > 0) {
            $detail .= sprintf(', %d template example row%s ignored',
                $summary['example_ignored'],
                $summary['example_ignored'] === 1 ? '' : 's',
            );
        }

        return back()
            ->with('warning', $headline.$detail.' — see the import summary.')
            ->with('import_summary', $summary);
    }

    /**
     * Downloadable CSV template for the import: the exact header the
     * parser reads (LeadImportService::COLUMNS) plus one obviously-fake
     * example row (Ofcom-reserved phone, example.com). A download, not
     * an upload — FileUploadService is deliberately not involved.
     */
    public function importTemplate(): \Illuminate\Http\Response
    {
        Gate::authorize('viewAny', Company::class);
        $this->authorizeScopeSection(ScopeArea::Leads);

        $csv = implode("\n", [
            implode(',', LeadImportService::COLUMNS),
            'Alex,Example,'.LeadImportService::TEMPLATE_EXAMPLE_EMAIL.',07700 900000,Example Bakery Ltd,Owner,1500,"Example row — delete it before importing"',
            '',
        ]);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="leads-import-template.csv"',
        ]);
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Company::class);

        $lead = Lead::findOrFail($id);
        // Scope (phase 3b-iii): blocks a lead outside the user's scope by
        // direct ID — None always; Assigned unless it's theirs (NULL-assigned
        // counts as not theirs); All/super_admin pass. Composes with the
        // per-method permission/state checks below.
        $this->authorizeScopeItem(ScopeArea::Leads, $lead);
        if ($lead->customer_id !== null) {
            return back()->with('error', 'Converted leads are read-only here. Edit the customer instead.');
        }

        $data = $this->validateRow($request);

        $before = $lead->only(['first_name', 'last_name', 'status', 'assigned_to']);
        $previousAssignee = $lead->assigned_to;
        $lead->update($data);

        $this->log($request, 'lead.updated', $lead->id, before: $before, after: $lead->only(['first_name', 'last_name', 'status', 'assigned_to']));

        if ($lead->assigned_to !== $previousAssignee) {
            app(NotificationService::class)->notifyLeadAssigned($lead, $request->user());
        }

        return back()->with('success', 'Lead updated.');
    }

    /**
     * Kanban + status-popover handler. JSON so the front-end
     * can flip a card optimistically without a full page swap.
     */
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Company::class);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'lost_reason' => ['nullable', 'string', 'max:1000', 'required_if:status,lost'],
        ]);

        $lead = Lead::findOrFail($id);
        // Scope (phase 3b-iii): blocks a lead outside the user's scope by
        // direct ID — None always; Assigned unless it's theirs (NULL-assigned
        // counts as not theirs); All/super_admin pass. Composes with the
        // per-method permission/state checks below.
        $this->authorizeScopeItem(ScopeArea::Leads, $lead);
        if ($lead->customer_id !== null) {
            return response()->json(['ok' => false, 'message' => 'Converted lead.'], 422);
        }

        $old = $lead->status;
        $lead->update([
            'status' => $data['status'],
            // Clearing lost_reason when leaving 'lost' avoids
            // stale reasons sitting on now-active leads.
            'lost_reason' => $data['status'] === 'lost'
                ? ($data['lost_reason'] ?? null)
                : null,
        ]);

        $this->log($request, 'lead.status_changed', $lead->id, after: [
            'from' => $old,
            'to' => $data['status'],
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Mint a Company + primary Contact from a lead, migrate the
     * lead's tasks + notes to the new customer, and stamp the
     * lead with customer_id + converted_at so it drops out of
     * the pipeline.
     *
     * Idempotency: refuses to run twice. The first call wins and
     * subsequent attempts surface "already converted" rather than
     * silently mint a second customer.
     */
    public function convert(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Company::class);

        $lead = Lead::findOrFail($id);
        // Scope (phase 3b-iii): blocks a lead outside the user's scope by
        // direct ID — None always; Assigned unless it's theirs (NULL-assigned
        // counts as not theirs); All/super_admin pass. Composes with the
        // per-method permission/state checks below.
        $this->authorizeScopeItem(ScopeArea::Leads, $lead);

        // convert MINTS a Company + Contact — a companies.manage action. The
        // route already requires leads.manage; require companies.manage too so
        // convert isn't a back-door to customer creation for a leads-only role
        // (direct customer creation requires companies.manage). super_admin bypasses.
        Gate::authorize('create', Company::class);

        if ($lead->customer_id !== null) {
            return back()->with('error', 'This lead has already been converted.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['restaurant', 'bar', 'bakery', 'cafe', 'venue', 'other'])],
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:120',
            'postcode' => 'required|string|max:20',
            'country' => 'nullable|string|size:2',
            'trading_name' => 'nullable|string|max:255',
            'company_number' => 'nullable|string|max:50',
            'vat_number' => 'nullable|string|max:50',
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $customer = DB::transaction(function () use ($lead, $data, $request) {
            // Map lead source → customer acquisition_channel.
            // Most enum values are shared verbatim; we coerce the
            // odd ones onto the closest customer-side bucket.
            $channelMap = [
                'manual' => 'other',
                'phone' => 'other',
                'facebook' => 'social_media',
                'import' => 'other',
            ];
            $channel = $channelMap[$lead->source] ?? $lead->source;

            $customer = Company::create([
                'name' => $data['name'],
                'trading_name' => $data['trading_name'] ?? null,
                'company_number' => $data['company_number'] ?? null,
                'vat_number' => $data['vat_number'] ?? null,
                'type' => $data['type'],
                'address_line1' => $data['address_line1'],
                'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'],
                'postcode' => $data['postcode'],
                'country' => $data['country'] ?? 'GB',
                'pipeline_stage' => 'prospect',
                'acquisition_channel' => $channel,
                'channel_detail' => $lead->source_detail,
                'assigned_to' => $data['assigned_to'] ?? $lead->assigned_to,
            ]);

            // Primary contact carries the lead's identity bits.
            // We require one of email/phone before creating a
            // contact — name alone is too thin for a contact row
            // (we still need *some* way to reach them).
            if ($lead->email !== null || $lead->phone !== null) {
                $contact = Contact::create([
                    'customer_id' => $customer->id,
                    'name' => $lead->name,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'job_title' => $lead->job_title,
                    'role' => 'owner',
                    'is_primary' => true,
                ]);

                // Same people-layer funnel as CompanyController::store: dedupe
                // the human by email and link the Person + customer_person
                // pivot. Previously convert() created this Contact with
                // person_id null — orphaned from the cross-company identity,
                // so a converted lead's owner was invisible to Company::people.
                $people = app(PersonService::class);
                $person = $people->createOrLinkFromContact(
                    null, // no operator-picked person on the convert form
                    $lead->name,
                    $lead->email,
                    $request->user(),
                );
                $contact->update(['person_id' => $person->id]);
                $people->attachCompany(
                    $person,
                    $customer,
                    PersonRole::Owner, // the primary contact is always 'owner' here
                    $lead->job_title,
                    $request->user(),
                );
            }

            // Migrate any tasks + notes that hung off the lead.
            // Setting lead_id to null preserves the audit (the
            // customer side becomes the new home) without
            // double-attaching.
            Task::where('lead_id', $lead->id)->update([
                'customer_id' => $customer->id,
                'lead_id' => null,
            ]);
            Note::where('lead_id', $lead->id)->update([
                'customer_id' => $customer->id,
                'lead_id' => null,
            ]);

            $lead->update([
                'customer_id' => $customer->id,
                'status' => 'won',
                'converted_at' => now(),
            ]);

            // If the lead carried a referrer (captured at public-form
            // submission), create the immutable CustomerReferral now.
            // No-op when the lead has no referrer. This is the path that
            // used to silently drop referral identity at conversion.
            app(AttributionService::class)->attributeFromLead($customer, $lead);

            $this->log($request, 'lead.converted', $lead->id, after: [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
            ]);

            return $customer;
        });

        return redirect('/companies/'.$customer->id)
            ->with('success', $lead->name.' converted to customer '.$customer->name.'.');
    }

    /**
     * Approve a registered deal from the referral-review queue. Opens the
     * fixed 90-day protection window (clock starts now).
     */
    public function approveReferral(int $id, Request $request, DealRegistrationService $service): RedirectResponse
    {
        Gate::authorize('review', Lead::class);

        $lead = Lead::findOrFail($id);
        // Scope (phase 3b-iii): blocks a lead outside the user's scope by
        // direct ID — None always; Assigned unless it's theirs (NULL-assigned
        // counts as not theirs); All/super_admin pass. Composes with the
        // per-method permission/state checks below.
        $this->authorizeScopeItem(ScopeArea::Leads, $lead);
        if ($lead->referral_status !== ReferralStatus::PendingReview) {
            return back()->with('error', 'This deal is not awaiting review.');
        }

        $service->approve($lead, $request->user());

        return back()->with('success', 'Deal approved — protected for '.config('referrals.protection_days', 90).' days.');
    }

    /**
     * Reject a registered deal with a reason (stored on the lead).
     */
    public function rejectReferral(int $id, Request $request, DealRegistrationService $service): RedirectResponse
    {
        Gate::authorize('review', Lead::class);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $lead = Lead::findOrFail($id);
        // Scope (phase 3b-iii): blocks a lead outside the user's scope by
        // direct ID — None always; Assigned unless it's theirs (NULL-assigned
        // counts as not theirs); All/super_admin pass. Composes with the
        // per-method permission/state checks below.
        $this->authorizeScopeItem(ScopeArea::Leads, $lead);
        if ($lead->referral_status !== ReferralStatus::PendingReview) {
            return back()->with('error', 'This deal is not awaiting review.');
        }

        $service->reject($lead, $request->user(), $data['reason']);

        return back()->with('success', 'Deal rejected.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Company::class);

        $lead = Lead::findOrFail($id);
        // Scope (phase 3b-iii): blocks a lead outside the user's scope by
        // direct ID — None always; Assigned unless it's theirs (NULL-assigned
        // counts as not theirs); All/super_admin pass. Composes with the
        // per-method permission/state checks below.
        $this->authorizeScopeItem(ScopeArea::Leads, $lead);

        if ($lead->customer_id !== null) {
            return back()->with('error', 'Cannot delete a converted lead.');
        }

        DB::transaction(function () use ($lead, $request) {
            // Detach (don't delete) any tasks/notes — they may
            // still be useful to the operator under a different
            // parent. Drop the FK so the cascade doesn't take
            // them down with the lead.
            Task::where('lead_id', $lead->id)->update(['lead_id' => null]);
            Note::where('lead_id', $lead->id)->update(['lead_id' => null]);

            $snapshot = ['name' => $lead->name, 'source' => $lead->source];
            $leadId = $lead->id;
            $lead->delete();

            $this->log($request, 'lead.deleted', $leadId, before: $snapshot);
        });

        return redirect('/leads')->with('success', 'Lead removed.');
    }

    /**
     * Shared validator. Kept compact because the pipeline + new
     * lead slide-over both submit roughly the same payload.
     *
     * @return array<string, mixed>
     */
    private function validateRow(Request $request): array
    {
        return $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'status' => ['required', Rule::in(self::STATUSES)],
            'source' => ['required', Rule::in(self::SOURCES)],
            'source_detail' => 'nullable|string|max:255',
            'assigned_to' => 'nullable|integer|exists:users,id',
            // max mirrors the DECIMAL(10,2) column (aligned with the CSV
            // import rules) — oversize values otherwise pass is_numeric
            // and die as a QueryException under strict mode.
            'estimated_value' => 'nullable|numeric|min:0|max:99999999.99',
            'notes' => 'nullable|string|max:5000',
        ]);
    }

    /**
     * Slim mapping for the pipeline cards + list rows.
     *
     * @return array<string, mixed>
     */
    private function mapLead(Lead $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'initials' => $l->initials,
            'first_name' => $l->first_name,
            'last_name' => $l->last_name,
            'email' => $l->email,
            'phone' => $l->phone,
            'company' => $l->company,
            'job_title' => $l->job_title,
            'status' => $l->status,
            'status_colour' => $l->status_colour,
            'source' => $l->source,
            'source_detail' => $l->source_detail,
            'estimated_value' => $l->estimated_value !== null ? (float) $l->estimated_value : null,
            'assigned_to' => $l->assignedTo ? [
                'id' => $l->assignedTo->id,
                'name' => $l->assignedTo->name,
                'avatar_colour' => $l->assignedTo->avatar_colour,
            ] : null,
            'is_converted' => $l->is_converted,
            'customer_id' => $l->customer_id,
            'notes' => $l->notes,
            // Deal-registration: surfaced so the kanban card / detail can
            // show the "Protected — {referrer} until {date}" badge.
            'referral_status' => $l->referral_status?->value,
            'referrer_name' => $l->referrer?->user?->name,
            'protected_until' => $l->protected_until?->format('d M Y'),
            'created_at' => $l->created_at?->format('d M Y'),
            'created_at_diff' => $l->created_at?->diffForHumans(),
        ];
    }

    /**
     * Row mapping for the referral-review queue (pending_review deals).
     *
     * @return array<string, mixed>
     */
    private function mapReferralReview(Lead $l): array
    {
        return [
            'id' => $l->id,
            'company' => $l->company,
            'contact_name' => $l->name,
            'email' => $l->email,
            'phone' => $l->phone,
            'product' => $l->source_detail,
            'referrer_name' => $l->referrer?->user?->name,
            'registered_at' => $l->registered_at?->format('d M Y'),
            'registered_at_diff' => $l->registered_at?->diffForHumans(),
            'notes' => $l->notes,
        ];
    }

    /**
     * Detail mapping. Adds the eager-loaded relationships the
     * Show page needs without re-running queries client-side.
     *
     * @return array<string, mixed>
     */
    private function mapLeadDetail(Lead $l): array
    {
        $daysInPipeline = $l->created_at !== null
            ? (int) abs(now()->diffInDays($l->created_at, false))
            : 0;

        return [
            ...$this->mapLead($l),
            'lost_reason' => $l->lost_reason,
            'converted_at' => $l->converted_at?->format('d M Y'),
            'days_in_pipeline' => $daysInPipeline,
            // created_by is NOT NULL — createdBy always resolves.
            'created_by' => [
                'id' => $l->createdBy->id,
                'name' => $l->createdBy->name,
            ],
            'customer' => $l->customer ? [
                'id' => $l->customer->id,
                'name' => $l->customer->name,
            ] : null,
            'tasks' => $l->tasks->map(fn (Task $t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'type' => $t->type,
                'type_icon' => $t->type_icon,
                'type_colour' => $t->type_colour,
                'status' => $t->status,
                'priority' => $t->priority,
                'due_at' => $t->due_at?->toIso8601String(),
                'is_overdue' => $t->is_overdue,
                'assigned_to' => $t->assignedTo ? [
                    'id' => $t->assignedTo->id,
                    'name' => $t->assignedTo->name,
                    'avatar_colour' => $t->assignedTo->avatar_colour,
                ] : null,
            ])->values(),
            // Larastan can't narrow the HasMany return type for the
            // freshly-added notesThread relation; ignore the typed
            // closure shape check.
            /** @phpstan-ignore-next-line argument.type */
            'notes_thread' => $l->notesThread->map(fn (Note $n): array => [
                'id' => $n->id,
                'body' => $n->body,
                'type' => $n->type,
                'is_pinned' => $n->is_pinned,
                'created_at' => $n->created_at?->diffForHumans(),
                'author' => $n->createdBy ? [
                    'id' => $n->createdBy->id,
                    'name' => $n->createdBy->name,
                    'avatar_colour' => $n->createdBy->avatar_colour,
                ] : null,
            ])->values(),
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
            'entity_type' => 'lead',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
