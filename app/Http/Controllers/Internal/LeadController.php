<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
 *     It mirrors CustomerController::store as closely as
 *     possible: Customer::create + primary Contact + carry
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

    private const SOURCES = [
        'manual', 'landing_page', 'facebook', 'google',
        'referral', 'email', 'phone', 'event',
        'word_of_mouth', 'other',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $userId = $request->user()->id;

        $leads = Lead::query()
            ->with([
                'assignedTo:id,name,avatar_colour',
                'createdBy:id,name',
            ])
            ->whereNull('customer_id')
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

        $summary = [
            'total' => Lead::whereNull('customer_id')->count(),
            'new' => Lead::where('status', 'new')->whereNull('customer_id')->count(),
            'qualified_plus' => Lead::whereIn('status', ['qualified', 'proposal', 'negotiation'])
                ->whereNull('customer_id')->count(),
            'total_pipeline_value' => (float) Lead::whereNull('customer_id')
                ->whereNotIn('status', ['lost', 'won'])
                ->sum('estimated_value'),
            'converted_this_month' => Lead::whereNotNull('customer_id')
                ->where('converted_at', '>=', now()->startOfMonth())
                ->count(),
        ];

        $staff = User::whereIn('role', ['super_admin', 'staff'])
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_colour']);

        return Inertia::render('Internal/Leads/Index', [
            'leads' => $leads,
            'summary' => $summary,
            'staff' => $staff,
            'statuses' => self::STATUSES,
            'sources' => self::SOURCES,
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
        Gate::authorize('viewAny', Customer::class);

        $lead = Lead::with([
            'assignedTo:id,name,avatar_colour',
            'createdBy:id,name',
            'customer:id,name',
            'tasks' => fn ($q) => $q->with('assignedTo:id,name,avatar_colour')
                ->orderByRaw('due_at IS NULL, due_at ASC'),
            'notesThread' => fn ($q) => $q->with('createdBy:id,name,avatar_colour')
                ->orderByDesc('created_at'),
        ])->findOrFail($id);

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
        Gate::authorize('viewAny', Customer::class);

        $data = $this->validateRow($request);

        DB::transaction(function () use ($data, $request) {
            $lead = Lead::create([
                ...$data,
                'created_by' => $request->user()->id,
            ]);

            $this->log($request, 'lead.created', $lead->id, after: [
                'name' => $lead->name,
                'source' => $lead->source,
            ]);
        });

        return back()->with('success', 'Lead added.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $lead = Lead::findOrFail($id);
        if ($lead->customer_id !== null) {
            return back()->with('error', 'Converted leads are read-only here. Edit the customer instead.');
        }

        $data = $this->validateRow($request);

        $before = $lead->only(['first_name', 'last_name', 'status', 'assigned_to']);
        $lead->update($data);

        $this->log($request, 'lead.updated', $lead->id, before: $before, after: $lead->only(['first_name', 'last_name', 'status', 'assigned_to']));

        return back()->with('success', 'Lead updated.');
    }

    /**
     * Kanban + status-popover handler. JSON so the front-end
     * can flip a card optimistically without a full page swap.
     */
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'lost_reason' => ['nullable', 'string', 'max:1000', 'required_if:status,lost'],
        ]);

        $lead = Lead::findOrFail($id);
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
     * Mint a Customer + primary Contact from a lead, migrate the
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
        Gate::authorize('viewAny', Customer::class);

        $lead = Lead::findOrFail($id);

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
            ];
            $channel = $channelMap[$lead->source] ?? $lead->source;

            $customer = Customer::create([
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
                Contact::create([
                    'customer_id' => $customer->id,
                    'name' => $lead->name,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'job_title' => $lead->job_title,
                    'role' => 'owner',
                    'is_primary' => true,
                ]);
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

            $this->log($request, 'lead.converted', $lead->id, after: [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
            ]);

            return $customer;
        });

        return redirect('/customers/'.$customer->id)
            ->with('success', $lead->name.' converted to customer '.$customer->name.'.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $lead = Lead::findOrFail($id);

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
            'estimated_value' => 'nullable|numeric|min:0',
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
            'created_at' => $l->created_at?->format('d M Y'),
            'created_at_diff' => $l->created_at?->diffForHumans(),
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
