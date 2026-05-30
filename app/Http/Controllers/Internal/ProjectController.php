<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects — the top of the project management hierarchy.
 *
 * The controller is responsible for the list view, the detail view
 * (with eager-loaded milestones/tasks/team/time entries), the standard
 * CRUD endpoints, AND the time-to-invoice conversion path. The last is
 * here rather than in TimeEntryController because the invoice scope is
 * "a project, plus a subset of its unbilled entries" — natural fit on
 * the project resource.
 *
 * Authorisation: every method gates through CustomerPolicy::viewAny
 * (which is what restricts the project surface to super_admin/staff).
 * A future Sprint 2 may add a tighter per-project policy; until then
 * the role middleware on the routes is the primary line of defence.
 */
class ProjectController extends Controller
{
    private const STATUSES = ['planning', 'active', 'on_hold', 'completed', 'cancelled'];

    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $userId = $request->user()->id;

        $projects = Project::query()
            ->whereNull('archived_at')
            ->with([
                'customer:id,name',
                'lead:id,name,avatar_colour',
                'members:id,name,avatar_colour',
                'milestones:id,project_id,status',
            ])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'complete'),
            ])
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->integer('customer_id'), fn ($q, int $id) => $q->where('customer_id', $id))
            ->when($request->boolean('assigned_to_me'), fn ($q) => $q->whereHas('members', fn ($q2) => $q2->where('user_id', $userId)))
            ->when($request->string('priority')->toString() !== '', fn ($q) => $q->where('priority', $request->string('priority')))
            ->when($request->string('search')->toString() !== '', fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'))
            // Active projects float to the top; cancelled sinks. Inside
            // each band, soonest due first so the operator's eye lands
            // on the actual deadline pressure.
            ->orderByRaw("CASE status
                WHEN 'active' THEN 1
                WHEN 'on_hold' THEN 2
                WHEN 'planning' THEN 3
                WHEN 'completed' THEN 4
                WHEN 'cancelled' THEN 5
                END")
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Project $p): array => $this->mapProject($p));

        $summary = [
            'total' => Project::whereNull('archived_at')->count(),
            'active' => Project::where('status', 'active')->whereNull('archived_at')->count(),
            'on_hold' => Project::where('status', 'on_hold')->whereNull('archived_at')->count(),
            'overdue' => Project::where('status', 'active')
                ->whereNull('archived_at')
                ->where('due_date', '<', now())
                ->count(),
        ];

        $customers = Customer::whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        $staff = User::whereIn('role', ['super_admin', 'staff'])
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_colour']);

        return Inertia::render('Internal/Projects/Index', [
            'projects' => $projects,
            'summary' => $summary,
            'filters' => [
                'status' => $request->string('status')->toString(),
                'customer_id' => $request->integer('customer_id') ?: null,
                'priority' => $request->string('priority')->toString(),
                'search' => $request->string('search')->toString(),
                'assigned_to_me' => $request->boolean('assigned_to_me'),
            ],
            'customers' => $customers,
            'staff' => $staff,
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
        ]);
    }

    public function show(int $id, Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $project = Project::with([
            'customer:id,name,city',
            'lead:id,name,avatar_colour',
            'createdBy:id,name',
            'members:id,name,avatar_colour,role',
            'milestones' => fn ($q) => $q->orderBy('sort_order')
                ->withCount([
                    'tasks',
                    'tasks as completed_count' => fn ($q2) => $q2->where('status', 'complete'),
                ]),
            'tasks' => fn ($q) => $q
                ->with(['assignedTo:id,name,avatar_colour', 'milestone:id,title'])
                ->orderBy('milestone_id')
                ->orderBy('sort_order'),
            'timeEntries' => fn ($q) => $q
                ->with('user:id,name,avatar_colour')
                ->with('task:id,title')
                ->orderByDesc('logged_at')
                ->take(50),
        ])->whereNull('archived_at')->findOrFail($id);

        // Time aggregates run against the table directly so the
        // headline numbers don't drift if the eager-load above is
        // truncated by ->take(). The unbilled amount sums via the
        // accessor on each row to honour entry- vs project-level
        // rate overrides.
        $billableEntries = TimeEntry::with('project:id,hourly_rate')
            ->where('project_id', $id)
            ->where('is_billable', true)
            ->whereNull('invoice_id')
            ->get();

        $timeSummary = [
            'total_minutes' => (int) TimeEntry::where('project_id', $id)->sum('minutes'),
            'total_hours' => round(((int) TimeEntry::where('project_id', $id)->sum('minutes')) / 60, 2),
            'billable_hours' => round(((int) TimeEntry::where('project_id', $id)->where('is_billable', true)->sum('minutes')) / 60, 2),
            'unbilled_hours' => round((int) $billableEntries->sum('minutes') / 60, 2),
            'unbilled_amount' => round((float) $billableEntries->sum(fn (TimeEntry $e): float => $e->billable_amount), 2),
            'billed_hours' => round(((int) TimeEntry::where('project_id', $id)->where('is_billable', true)->whereNotNull('invoice_id')->sum('minutes')) / 60, 2),
        ];

        $staff = User::whereIn('role', ['super_admin', 'staff'])
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_colour']);

        $billingEntities = DB::table('billing_entities')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $activity = ActivityLog::where(function ($q) use ($id, $project) {
            $q->where(function ($q2) use ($id) {
                $q2->where('entity_type', 'project')->where('entity_id', $id);
            })->orWhere(function ($q2) use ($project) {
                $q2->where('entity_type', 'task')
                    ->whereIn('entity_id', $project->tasks->pluck('id'));
            });
        })
            ->orderByDesc('created_at')
            ->take(50)
            ->get();

        return Inertia::render('Internal/Projects/Show', [
            'project' => $this->mapProjectDetail($project),
            'time_summary' => $timeSummary,
            'staff' => $staff,
            'billing_entities' => $billingEntities,
            'activity' => $activity->map(fn (ActivityLog $a): array => [
                'id' => $a->id,
                'action' => $a->action,
                'entity_type' => $a->entity_type,
                'entity_id' => $a->entity_id,
                'after' => $a->after,
                'before' => $a->before,
                'created_at' => $a->created_at?->toIso8601String(),
                'time_ago' => $a->created_at?->diffForHumans(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $data = $this->validate($request);

        $project = DB::transaction(function () use ($request, $data) {
            $project = Project::create([
                'customer_id' => $data['customer_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'priority' => $data['priority'],
                'colour' => $data['colour'],
                'start_date' => $data['start_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'budget' => $data['budget'] ?? null,
                'hourly_rate' => $data['hourly_rate'] ?? null,
                'project_lead' => $data['project_lead'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // Lead is automatically a member with role=lead. We use
            // syncWithoutDetaching so we don't double-attach if the
            // operator also picked them in member_ids.
            if (! empty($data['project_lead'])) {
                $project->members()->syncWithoutDetaching([
                    $data['project_lead'] => ['role' => 'lead', 'joined_at' => now()],
                ]);
            }

            foreach ($data['member_ids'] ?? [] as $uid) {
                if ((int) $uid === (int) ($data['project_lead'] ?? 0)) {
                    continue; // already attached as lead
                }
                $project->members()->syncWithoutDetaching([
                    $uid => ['role' => 'member', 'joined_at' => now()],
                ]);
            }

            $this->log($request, 'project.created', $project->id, after: [
                'title' => $project->title,
                'customer_id' => $project->customer_id,
                'status' => $project->status,
            ]);

            return $project;
        });

        return redirect('/projects/'.$project->id)->with('success', 'Project created.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $project = Project::findOrFail($id);
        $data = $this->validate($request);

        DB::transaction(function () use ($project, $data, $request) {
            $before = $project->only(['title', 'status', 'priority', 'due_date']);

            $project->update([
                'customer_id' => $data['customer_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'priority' => $data['priority'],
                'colour' => $data['colour'],
                'start_date' => $data['start_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'budget' => $data['budget'] ?? null,
                'hourly_rate' => $data['hourly_rate'] ?? null,
                'project_lead' => $data['project_lead'] ?? null,
                // Status transition to 'completed' stamps completed_at;
                // moving back out of completed clears it so we don't
                // carry stale timestamps on reopened projects.
                'completed_at' => $data['status'] === 'completed' ? ($project->completed_at ?? now()) : null,
            ]);

            // Refresh team membership. Simpler than diff'ing — we hold
            // the lock for tens of milliseconds at most.
            if (isset($data['member_ids']) || isset($data['project_lead'])) {
                $sync = [];
                if (! empty($data['project_lead'])) {
                    $sync[$data['project_lead']] = ['role' => 'lead', 'joined_at' => now()];
                }
                foreach ($data['member_ids'] ?? [] as $uid) {
                    if (isset($sync[$uid])) {
                        continue;
                    }
                    $sync[$uid] = ['role' => 'member', 'joined_at' => now()];
                }
                $project->members()->sync($sync);
            }

            $this->log($request, 'project.updated', $project->id, before: $before, after: [
                'title' => $project->title,
                'status' => $project->status,
                'priority' => $project->priority,
                'due_date' => $project->due_date?->toDateString(),
            ]);
        });

        return back()->with('success', 'Project updated.');
    }

    /**
     * Archive — soft-delete via archived_at. We preserve time entries,
     * tasks and milestones so historical billing remains queryable.
     */
    public function destroy(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $project = Project::findOrFail($id);

        $project->update(['archived_at' => now()]);

        $this->log($request, 'project.archived', $project->id, after: [
            'title' => $project->title,
        ]);

        return redirect('/projects')->with('success', 'Project archived.');
    }

    /**
     * Generate a draft invoice from a set of unbilled, billable time
     * entries. The operator picks which entries to include and the
     * billing entity; we sum minutes, apply the rate, and produce a
     * single invoice line summarising the work. The individual
     * entries are then stamped with the new invoice_id so they no
     * longer appear in the unbilled list.
     */
    public function generateInvoice(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $project = Project::findOrFail($id);

        $data = $request->validate([
            'entry_ids' => 'required|array|min:1',
            'entry_ids.*' => 'integer|exists:time_entries,id',
            'billing_entity_id' => 'required|integer|exists:billing_entities,id',
            'hourly_rate' => 'nullable|numeric|min:0',
        ]);

        $entries = TimeEntry::with('project:id,hourly_rate')
            ->whereIn('id', $data['entry_ids'])
            ->where('project_id', $id)
            ->whereNull('invoice_id')
            ->where('is_billable', true)
            ->get();

        if ($entries->isEmpty()) {
            return back()->with('error', 'No unbilled billable entries found in the selection.');
        }

        $invoice = DB::transaction(function () use ($entries, $project, $data, $request) {
            $rate = $data['hourly_rate'] ?? ($project->hourly_rate !== null ? (float) $project->hourly_rate : 0.0);
            $totalMinutes = (int) $entries->sum('minutes');
            $totalHours = round($totalMinutes / 60, 2);
            $amount = round($totalHours * (float) $rate, 2);

            // Invoice headline numbers: time-based invoices ship at
            // zero VAT here; the operator can edit the draft to add
            // VAT before sending if the customer is registered.
            $invoice = Invoice::create([
                'customer_id' => $project->customer_id,
                'billing_entity_id' => $data['billing_entity_id'],
                'number' => Invoice::generateNextNumber(),
                'type' => 'invoice',
                'status' => 'draft',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'subtotal' => $amount,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'total' => $amount,
                'notes' => 'Time-based invoice for project: '.$project->title,
                'created_by' => $request->user()->id,
            ]);

            $line = InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'product_id' => null,
                'plan_id' => null,
                'description' => $project->title.' — '.$totalHours.' hours',
                'note' => $entries->count().' time entries ('
                    .$entries->min('logged_at').' to '
                    .$entries->max('logged_at').')',
                'quantity' => $totalHours,
                'unit_price' => $rate,
                'amount' => $amount,
                'sort_order' => 0,
            ]);

            // Stamp each entry so it no longer surfaces in the
            // unbilled list. We update in one query for atomicity.
            TimeEntry::whereIn('id', $entries->pluck('id'))
                ->update([
                    'invoice_id' => $invoice->id,
                    'invoice_line_id' => $line->id,
                ]);

            $this->log($request, 'project.invoice_generated', $project->id, after: [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'hours' => $totalHours,
                'amount' => $amount,
                'entry_count' => $entries->count(),
            ]);

            return $invoice;
        });

        return redirect('/invoices/'.$invoice->id)
            ->with('success', 'Draft invoice '.$invoice->number.' created from time entries.');
    }

    /**
     * Validation shared between store and update. Centralised so the
     * two paths can't drift apart accidentally.
     *
     * @return array<string, mixed>
     */
    private function validate(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'description' => 'nullable|string|max:5000',
            'status' => ['required', Rule::in(self::STATUSES)],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'colour' => 'required|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
            'budget' => 'nullable|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
            'project_lead' => 'nullable|integer|exists:users,id',
            'member_ids' => 'nullable|array',
            'member_ids.*' => 'integer|exists:users,id',
        ]);
    }

    /**
     * Slim mapping for the list view — just what the project card needs.
     *
     * @return array<string, mixed>
     */
    private function mapProject(Project $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title,
            'description' => $p->description,
            'status' => $p->status,
            'status_colour' => $p->status_colour,
            'priority' => $p->priority,
            'colour' => $p->colour,
            'customer_id' => $p->customer_id,
            'customer_name' => $p->customer?->name,
            'due_date' => $p->due_date?->format('d M Y'),
            'due_date_raw' => $p->due_date?->toDateString(),
            'is_overdue' => $p->is_overdue,
            'budget' => $p->budget,
            'hourly_rate' => $p->hourly_rate,
            'lead' => $p->lead ? [
                'id' => $p->lead->id,
                'name' => $p->lead->name,
                'avatar_colour' => $p->lead->avatar_colour,
            ] : null,
            'members' => $p->members->take(4)->map(fn (User $m): array => [
                'id' => $m->id,
                'name' => $m->name,
                'avatar_colour' => $m->avatar_colour,
            ])->values(),
            'total_members' => $p->members->count(),
            'progress' => $p->progress,
            'tasks_count' => $p->tasks_count ?? 0,
            'completed_tasks' => $p->completed_tasks_count ?? 0,
            'milestones_total' => $p->milestones->count(),
            'milestones_done' => $p->milestones->where('status', 'completed')->count(),
        ];
    }

    /**
     * Detail mapping for the project Show page. Carries everything the
     * tabs need so the page renders in a single render() round-trip.
     *
     * @return array<string, mixed>
     */
    private function mapProjectDetail(Project $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title,
            'description' => $p->description,
            'status' => $p->status,
            'status_colour' => $p->status_colour,
            'priority' => $p->priority,
            'colour' => $p->colour,
            'customer_id' => $p->customer_id,
            'customer_name' => $p->customer?->name,
            'customer_city' => $p->customer?->city,
            'start_date' => $p->start_date?->format('d M Y'),
            'due_date' => $p->due_date?->format('d M Y'),
            'due_date_raw' => $p->due_date?->toDateString(),
            'is_overdue' => $p->is_overdue,
            'budget' => $p->budget,
            'hourly_rate' => $p->hourly_rate,
            'progress' => $p->progress,
            'lead' => $p->lead ? [
                'id' => $p->lead->id,
                'name' => $p->lead->name,
                'avatar_colour' => $p->lead->avatar_colour,
            ] : null,
            // FK is non-nullable in the schema so phpstan sees
            // $p->createdBy as always-present.
            'created_by' => [
                'id' => $p->createdBy->id,
                'name' => $p->createdBy->name,
            ],
            'members' => $p->members->map(fn (User $m): array => [
                'id' => $m->id,
                'name' => $m->name,
                'avatar_colour' => $m->avatar_colour,
                'role' => $m->pivot->role ?? 'member',
            ])->values(),
            'milestones' => $p->milestones->map(fn ($m): array => [
                'id' => $m->id,
                'title' => $m->title,
                'description' => $m->description,
                'due_date' => $m->due_date?->format('d M Y'),
                'due_date_raw' => $m->due_date?->toDateString(),
                'status' => $m->status,
                'sort_order' => $m->sort_order,
                'is_overdue' => $m->is_overdue,
                'progress' => $m->progress,
                'tasks_count' => $m->tasks_count ?? 0,
                'completed_count' => $m->completed_count ?? 0,
            ])->values(),
            'tasks' => $p->tasks->map(fn ($t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'type' => $t->type,
                'type_icon' => $t->type_icon,
                'status' => $t->status,
                'priority' => $t->priority,
                'milestone_id' => $t->milestone_id,
                'milestone_title' => $t->milestone?->title,
                'sort_order' => $t->sort_order,
                'due_at' => $t->due_at?->toIso8601String(),
                'is_overdue' => $t->is_overdue,
                'estimated_hours' => $t->estimated_hours,
                'total_hours' => $t->total_hours,
                'blocked_reason' => $t->blocked_reason,
                'assigned_to' => $t->assignedTo ? [
                    'id' => $t->assignedTo->id,
                    'name' => $t->assignedTo->name,
                    'avatar_colour' => $t->assignedTo->avatar_colour,
                ] : null,
            ])->values(),
            // task/user/project are all non-nullable FKs on time_entries
            // so phpstan flags ?-> as never-null; we drop the operator.
            'time_entries' => $p->timeEntries->map(fn ($e): array => [
                'id' => $e->id,
                'task_id' => $e->task_id,
                'task_title' => $e->task->title,
                'description' => $e->description,
                'minutes' => $e->minutes,
                'hours' => $e->hours,
                'logged_at' => $e->logged_at->format('d M Y'),
                'logged_at_raw' => $e->logged_at->toDateString(),
                'is_billable' => $e->is_billable,
                'hourly_rate' => $e->hourly_rate,
                'effective_rate' => $e->effective_rate,
                'billable_amount' => $e->billable_amount,
                'invoice_id' => $e->invoice_id,
                'user' => [
                    'id' => $e->user->id,
                    'name' => $e->user->name,
                    'avatar_colour' => $e->user->avatar_colour,
                ],
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
            'entity_type' => 'project',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
