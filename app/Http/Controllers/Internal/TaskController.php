<?php

namespace App\Http\Controllers\Internal;

use App\Enums\ScopeArea;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Services\FileUploadService;
use App\Services\GoogleCalendarService;
use App\Services\NotificationService;
use App\Support\ScopeEnforcer;
use App\Support\TaskDueDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
    private const TYPES = ['task', 'call', 'email', 'meeting', 'note'];

    private const PRIORITIES = ['low', 'medium', 'high'];

    /**
     * Activity detail page. Surfaces the full task + related context
     * (notes, child tasks, related open activities on the same
     * customer, plus the customer context card itself).
     */
    public function show(int $id, Request $request): Response
    {
        $task = Task::with([
            'customer:id,name,city',
            // assigned_to is needed for the lead-scope gate below (phase
            // 3b-iii) — a partial select without it reads NULL and would
            // wrongly block the owner from their own lead-linked activity.
            'lead:id,first_name,last_name,company,assigned_to',
            'contact:id,customer_id,name,email,phone,job_title',
            'assignedTo:id,name,avatar_colour,role',
            'createdBy:id,name',
            // A triage task can link to a support ticket; its subject is shown
            // on the activity page. That cross-reference rides the SAME composed
            // Support scope (phase 3b-iv) — under Assigned the ticket only loads
            // if it's the user's own (or unassigned + view_unassigned); else it
            // resolves null and the subject is hidden. The task itself stays
            // visible (its own Tasks-scope gate already ran). The WHERE on
            // assigned_to applies in SQL despite the column subset selected.
            'ticket' => function ($q) use ($request) {
                $q->select('id', 'subject', 'status', 'assigned_to');
                ScopeEnforcer::constrainRelation($q, $request->user(), ScopeArea::Support);
            },
            'parentTask:id,title,type',
            'notes' => fn ($q) => $q->orderBy('created_at')
                ->with('author:id,name,avatar_colour'),
            // Child tasks ride the Tasks scope too — under Assigned, a parent
            // surfaces only the user's own sub-tasks (super_admin/All: all).
            'childTasks' => function ($q) use ($request) {
                $q->with('assignedTo:id,name,avatar_colour');
                ScopeEnforcer::constrainRelation($q, $request->user(), ScopeArea::Tasks);
            },
            'attachments' => fn ($q) => $q->orderByDesc('created_at')
                ->with('uploadedBy:id,name'),
        ])->findOrFail($id);

        // Scope gate FIRST: under Assigned, a task not assigned to the user is
        // invisible regardless of the customer/lead view gate below — that's
        // the strict-assigned rule (visibility), which then composes with the
        // existing per-subject authorisation. None → 403; All/super_admin → ok.
        $this->authorizeScopeItem(ScopeArea::Tasks, $task);

        // Authorisation. Tasks attached to a customer ride the
        // CustomerPolicy::view check; orphan tasks (customer_id null)
        // fall back to ownership-or-super_admin so a staffer can't
        // browse another staffer's private TODO list.
        $user = $request->user();
        if ($task->customer_id !== null) {
            Gate::authorize('view', $task->customer);
        } elseif ($task->lead_id !== null) {
            // Lead-linked activities ride the LEAD's scope (phase 3b-iii):
            // "any staffer who can open the lead can open its activities" — now
            // ENFORCED, not just intended. Under Assigned, a task linked to a
            // lead that isn't yours is blocked, so the eager-loaded lead can't
            // leak. All/super_admin pass; a dangling lead (FK gone) has no
            // identity to leak, so it's allowed.
            abort_unless(
                $task->lead === null || ScopeEnforcer::allows($user, ScopeArea::Leads, $task->lead),
                403,
                'You do not have access to this lead.',
            );
        } else {
            abort_unless(
                $task->assigned_to === $user->id
                || $task->created_by === $user->id
                || $user->isSuperAdmin(),
                403,
                'You do not have access to this activity.',
            );
        }

        // Other open activities on the same customer — gives the
        // operator a one-click jump to anything else they need to
        // chase on this account.
        $related = $task->customer_id
            ? $this->scopeList(
                Task::where('customer_id', $task->customer_id)->where('id', '!=', $task->id),
                ScopeArea::Tasks,
            )
                // Active = anything not terminal. The PM sprint widened
                // the enum from {open,complete} to the six-state set,
                // so "open" is now expressed as "not in (complete,
                // cancelled)" — easier to read than listing the four
                // active states.
                ->whereNotIn('status', ['complete', 'cancelled'])
                ->orderByRaw('due_at IS NULL, due_at ASC')
                ->take(5)
                ->get(['id', 'type', 'title', 'due_at', 'status', 'priority', 'is_pinned'])
                ->map(fn (Task $r): array => [
                    'id' => $r->id,
                    'type' => $r->type,
                    'type_icon' => $r->type_icon,
                    'type_colour' => $r->type_colour,
                    'title' => $r->title,
                    'due_at' => $r->due_at?->toIso8601String(),
                    'is_overdue' => $r->is_overdue,
                    'status' => $r->status,
                    'priority' => $r->priority,
                    'is_pinned' => $r->is_pinned,
                ])
                ->all()
            : [];

        // Slim staff list for the linked-task assign-to picker.
        $staff = User::whereIn('role', ['super_admin', 'staff'])
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_colour'])
            ->all();

        // Customer-scoped contacts feed the inline "create linked task"
        // form's contact picker.
        $contacts = $task->customer_id
            ? Contact::where('customer_id', $task->customer_id)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->all()
            : [];

        return Inertia::render('Internal/Activities/Show', [
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'type' => $task->type,
                'type_icon' => $task->type_icon,
                'type_colour' => $task->type_colour,
                'description' => $task->description,
                'priority' => $task->priority,
                'status' => $task->status,
                'due_at' => $task->due_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'completed_at_human' => $task->completed_at?->diffForHumans(),
                'outcome' => $task->outcome,
                'duration_minutes' => $task->duration_minutes,
                'is_pinned' => $task->is_pinned,
                'is_overdue' => $task->is_overdue,
                'created_at' => $task->created_at?->format('d M Y, H:i'),
                // project_id drives the activity detail "Back" button — a
                // PM task returns to its board, a CRM task to its customer.
                'project_id' => $task->project_id,
                'customer_id' => $task->customer_id,
                // Support ticket this task was spawned from (triage tasks
                // only) — drives the "Open ticket" affordance. Null for
                // every non-support task and legacy triage tasks.
                'ticket' => $task->ticket ? [
                    'id' => $task->ticket->id,
                    'subject' => $task->ticket->subject,
                    'status' => $task->ticket->status,
                ] : null,
                'customer' => $task->customer ? [
                    'id' => $task->customer->id,
                    'name' => $task->customer->name,
                    'city' => $task->customer->city,
                ] : null,
                'lead_id' => $task->lead_id,
                'lead' => $task->lead ? [
                    'id' => $task->lead->id,
                    'name' => $task->lead->name,
                    'company' => $task->lead->company,
                ] : null,
                'contact' => $task->contact ? [
                    'id' => $task->contact->id,
                    'name' => $task->contact->name,
                    'email' => $task->contact->email,
                    'phone' => $task->contact->phone,
                    'job_title' => $task->contact->job_title,
                ] : null,
                'assigned_to' => $task->assigned_to,
                'assigned_to_user' => $task->assignedTo ? [
                    'id' => $task->assignedTo->id,
                    'name' => $task->assignedTo->name,
                    'avatar_colour' => $task->assignedTo->avatar_colour,
                ] : null,
                'created_by_user' => $task->createdBy ? [
                    'id' => $task->createdBy->id,
                    'name' => $task->createdBy->name,
                ] : null,
                'parent_task' => $task->parentTask ? [
                    'id' => $task->parentTask->id,
                    'title' => $task->parentTask->title,
                    'type' => $task->parentTask->type,
                ] : null,
                'notes' => $task->notes->map(fn ($n): array => [
                    'id' => $n->id,
                    'body' => $n->body,
                    'is_pinned' => $n->is_pinned,
                    'created_at' => $n->created_at?->toIso8601String(),
                    'created_at_human' => $n->created_at?->diffForHumans(),
                    'author' => $n->author ? [
                        'id' => $n->author->id,
                        'name' => $n->author->name,
                        'avatar_colour' => $n->author->avatar_colour,
                    ] : null,
                ])->values()->all(),
                'child_tasks' => $task->childTasks->map(fn (Task $c): array => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'type' => $c->type,
                    'type_icon' => $c->type_icon,
                    'type_colour' => $c->type_colour,
                    'status' => $c->status,
                    'priority' => $c->priority,
                    'due_at' => $c->due_at?->toIso8601String(),
                    'is_overdue' => $c->is_overdue,
                    'assigned_to_user' => $c->assignedTo ? [
                        'id' => $c->assignedTo->id,
                        'name' => $c->assignedTo->name,
                        'avatar_colour' => $c->assignedTo->avatar_colour,
                    ] : null,
                ])->values()->all(),
            ],
            'related' => $related,
            'staff' => $staff,
            'contacts' => $contacts,
            'attachments' => $task->attachments->map(fn (TaskAttachment $a): array => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime_type' => $a->mime_type,
                'size' => $this->formatBytes($a->size_bytes),
                'uploaded_by' => $a->uploadedBy?->name,
                'uploaded_at' => $a->created_at?->diffForHumans(),
                'download_url' => route('internal.tasks.attachments.download', $a->id),
                'icon' => $this->mimeIcon($a->mime_type),
            ])->values()->all(),
            // me lets the frontend default the "Assign to" picker in
            // the linked-task slide-over without an extra fetch.
            'me_id' => $user->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // None scope (phase 3b-ii) is walled off the Tasks area entirely —
        // no creating a task you could never see or act on (and no injecting
        // work into another user's queue). All/super_admin pass.
        $this->authorizeScopeSection(ScopeArea::Tasks);

        $data = $request->validate($this->rules(), $this->messages());

        $userId = $request->user()->id;
        $this->guardContactBelongsToCustomer($data);
        [$leadId, $customerId] = $this->guardSingleSubject($data);
        // Leads scope (phase 3b-iii): can't link a lead you can't see, even via
        // a forged lead_id (validation only checks existence).
        $this->guardLeadInScope($leadId);

        // Bucketing a NEW task onto a project/milestone is a project mutation on
        // that project — require projects.manage + Projects scope (mirrors the
        // reorderTasks re-bucket guard; the /tasks routes aren't under the
        // projects middleware). CRM tasks (no project_id/milestone_id) are
        // unaffected. Closes the create-task-onto-foreign-milestone IDOR.
        if (! empty($data['milestone_id'])) {
            $milestone = Milestone::find($data['milestone_id']);
            abort_unless(
                $milestone !== null && $this->canManageMilestoneProject($milestone),
                403,
                'You cannot create a task on a milestone in a project you do not manage.',
            );
            if (! empty($data['project_id']) && (int) $milestone->project_id !== (int) $data['project_id']) {
                abort(422, 'The milestone does not belong to the given project.');
            }
        } elseif (! empty($data['project_id'])) {
            abort_unless(
                $this->canManageProject(Project::find($data['project_id'])),
                403,
                'You cannot create a task on a project you do not manage.',
            );
        }

        $task = DB::transaction(function () use ($data, $request, $userId, $leadId, $customerId) {
            $task = Task::create([
                'type' => $data['type'],
                'title' => $data['title'] ?? $this->fallbackTitle($data['type']),
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
                'customer_id' => $customerId,
                'lead_id' => $leadId,
                // Contacts belong to customers — a lead-linked task
                // can't carry one.
                'contact_id' => $leadId ? null : ($data['contact_id'] ?? null),
                'parent_task_id' => $data['parent_task_id'] ?? null,
                // Project + milestone — set by the kanban quick-add
                // and the project Tasks-tab "+ Add task" affordance.
                // CRM tasks (no project) leave both null.
                'project_id' => $data['project_id'] ?? null,
                'milestone_id' => $data['milestone_id'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? $userId,
                'created_by' => $userId,
                // 'todo' is the new "open" — the entry state for the
                // PM kanban. CRM tasks created from the customer page
                // start here and the operator can later progress them
                // through the workflow.
                'status' => 'todo',
                // Notes are open-ended by design — no schedule (resolve()
                // forces note → null and bumps a bare date to 09:00).
                'due_at' => TaskDueDate::resolve($data),
                'duration_minutes' => $data['duration_minutes'] ?? null,
                // Calendar scheduling. Default to an all-day task; a
                // timed event (meeting) carries explicit start/end.
                ...$this->scheduleAttributes($data),
            ]);

            $this->logActivity($request, 'task.created', $task, after: [
                'title' => $task->title,
                'type' => $task->type,
            ]);

            return $task;
        });

        // Notify the assignee when a task lands on someone else's plate.
        // The service no-ops on self-assignment and on the opted-out.
        app(NotificationService::class)->notifyTaskAssigned($task, $request->user());

        $this->syncTaskToGoogle($request->user(), $task);

        return back()->with('success', $this->createdMessage($task));
    }

    /**
     * Options feed for the task form's "Link to" picker. Returns the
     * top matches for one subject type at a time; an empty query
     * returns a sensible default slice (customers A→Z, newest leads)
     * so the list isn't blank before the user types.
     */
    public function linkOptions(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $data = $request->validate([
            'type' => ['required', Rule::in(['lead', 'customer'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $q = trim($data['q'] ?? '');

        if ($data['type'] === 'customer') {
            $options = Customer::whereNull('archived_at')
                ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
                ->orderBy('name')
                ->take(8)
                ->get(['id', 'name', 'city'])
                ->map(fn (Customer $c): array => [
                    'id' => $c->id,
                    'label' => $c->name,
                    'sub' => $c->city,
                ]);
        } else {
            // Leads scope (phase 3b-iii): the "Link to lead" picker must only
            // offer leads the user can actually see — otherwise it enumerates
            // every lead's name/company. Assigned → own only; None → none;
            // All/super_admin → all. (Pairs with the store/update lead-scope
            // guard so a forged lead_id can't be linked either.)
            $options = $this->scopeList(Lead::query(), ScopeArea::Leads)
                ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                    ->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('company', 'like', "%{$q}%")))
                ->orderByDesc('created_at')
                ->take(8)
                ->get(['id', 'first_name', 'last_name', 'company', 'status'])
                ->map(fn (Lead $l): array => [
                    'id' => $l->id,
                    'label' => $l->name,
                    'sub' => $l->company,
                    'status' => $l->status,
                ]);
        }

        return response()->json(['options' => $options]);
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $task = Task::findOrFail($id);
        // Scope (phase 3b-ii) gates VISIBILITY before the ownership check
        // below gates MUTATION — the user must satisfy BOTH. None → 403;
        // Assigned → 403 unless it's their task; All/super_admin → passes.
        $this->authorizeScopeItem(ScopeArea::Tasks, $task);
        $user = $request->user();

        // Editable by the creator, the assignee, or a super_admin. Anyone
        // else editing somebody else's activity would mask ownership.
        if ($task->created_by !== $user->id
            && $task->assigned_to !== $user->id
            && ! $user->isSuperAdmin()
        ) {
            abort(403, 'You can only edit activities you own or are assigned to.');
        }

        $data = $request->validate($this->rules(forUpdate: true), $this->messages());
        $this->guardContactBelongsToCustomer($data);
        [$leadId, $customerId] = $this->guardSingleSubject($data, $task);
        // Leads scope (phase 3b-iii): can't re-link a task to a lead you can't
        // see, even via a forged lead_id.
        $this->guardLeadInScope($leadId);

        $before = $task->only(['title', 'type', 'priority', 'description', 'due_at', 'duration_minutes']);
        $previousAssignee = $task->assigned_to;

        DB::transaction(function () use ($task, $data, $leadId, $customerId) {
            $task->fill([
                'type' => $data['type'],
                'title' => $data['title'] ?? $this->fallbackTitle($data['type']),
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'] ?? $task->priority,
                'customer_id' => $customerId,
                'lead_id' => $leadId,
                'contact_id' => $leadId ? null : ($data['contact_id'] ?? null),
                'assigned_to' => $data['assigned_to'] ?? $task->assigned_to,
                'due_at' => TaskDueDate::resolve($data),
                'duration_minutes' => $data['duration_minutes'] ?? null,
                ...$this->scheduleAttributes($data),
            ])->save();
        });

        $this->logActivity($request, 'task.updated', $task, before: $before, after: $task->only(['title', 'type', 'priority', 'description', 'due_at', 'duration_minutes']));

        // Only notify when the assignee actually changed — a plain edit
        // (title, due date, …) shouldn't re-ping the same person.
        if ($task->assigned_to !== $previousAssignee) {
            app(NotificationService::class)->notifyTaskAssigned($task, $user);
        }

        $this->syncTaskToGoogle($user, $task);

        return back()->with('success', 'Activity updated.');
    }

    public function complete(int $id, Request $request): RedirectResponse
    {
        $task = Task::findOrFail($id);
        // Scope (phase 3b-ii) gates VISIBILITY before the ownership check
        // below gates MUTATION — the user must satisfy BOTH. None → 403;
        // Assigned → 403 unless it's their task; All/super_admin → passes.
        $this->authorizeScopeItem(ScopeArea::Tasks, $task);

        // Only the assignee or a super_admin can mark a task complete.
        $user = $request->user();
        if ($task->assigned_to !== $user->id && ! $user->isSuperAdmin()) {
            abort(403, 'You can only complete activities assigned to you.');
        }

        if ($task->status === 'complete') {
            return back();
        }

        $data = $request->validate([
            // Outcome is optional — a marked-done task without notes is
            // a valid state for routine work.
            'outcome' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($task, $data, $request) {
            $task->update([
                'status' => 'complete',
                'completed_at' => now(),
                'outcome' => $data['outcome'] ?? null,
            ]);

            $this->logActivity($request, 'task.completed', $task, after: [
                'title' => $task->title,
                'type' => $task->type,
                'has_outcome' => ! empty($data['outcome'] ?? null),
            ]);
        });

        // Completed work leaves the calendar.
        $this->removeTaskFromGoogle($user, $task);

        return back()->with('success', 'Activity completed.');
    }

    /**
     * Drag-to-reschedule from the MyWork calendar. Moves a task to a new
     * date/slot: an all-day drop updates due_at (kept at 09:00 for the
     * list views); a timed drop sets start_at/end_at and aligns due_at.
     * Returns JSON — FullCalendar's eventDrop calls it via fetch().
     */
    public function reschedule(int $id, Request $request): JsonResponse
    {
        $task = Task::findOrFail($id);
        // Scope (phase 3b-ii) gates VISIBILITY before the ownership check
        // below gates MUTATION — the user must satisfy BOTH. None → 403;
        // Assigned → 403 unless it's their task; All/super_admin → passes.
        $this->authorizeScopeItem(ScopeArea::Tasks, $task);
        $user = $request->user();

        // Same edit rule as update(): creator, assignee, or super_admin.
        if ($task->created_by !== $user->id
            && $task->assigned_to !== $user->id
            && ! $user->isSuperAdmin()
        ) {
            abort(403, 'You can only reschedule tasks you own or are assigned to.');
        }

        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['nullable', 'date', 'after_or_equal:start'],
            'all_day' => ['nullable', 'boolean'],
        ]);

        $allDay = ! array_key_exists('all_day', $data) || (bool) $data['all_day'];

        DB::transaction(function () use ($task, $data, $allDay): void {
            if ($allDay) {
                $task->update([
                    'is_all_day' => true,
                    // 09:00 matches parseDueAt — a bare date shouldn't
                    // read as "due at midnight" on the list views.
                    'due_at' => Carbon::parse($data['start'])->setTime(9, 0),
                    'start_at' => null,
                    'end_at' => null,
                ]);
            } else {
                $start = Carbon::parse($data['start']);
                $task->update([
                    'is_all_day' => false,
                    'start_at' => $start,
                    'end_at' => ! empty($data['end']) ? Carbon::parse($data['end']) : $start->copy()->addHour(),
                    'due_at' => $start,
                ]);
            }
        });

        $this->syncTaskToGoogle($user, $task);

        $this->logActivity($request, 'task.rescheduled', $task, after: [
            'is_all_day' => $task->is_all_day,
            'due_at' => $task->due_at?->toIso8601String(),
        ]);

        return response()->json(['ok' => true, 'task_id' => $task->id]);
    }

    /**
     * One-click complete from the My Work list. Same effect as
     * complete() but with no outcome prompt — the checkbox just
     * marks the task done and drops it off the list.
     */
    public function quickComplete(int $id, Request $request): RedirectResponse
    {
        $task = Task::findOrFail($id);
        // Scope (phase 3b-ii) gates VISIBILITY before the ownership check
        // below gates MUTATION — the user must satisfy BOTH. None → 403;
        // Assigned → 403 unless it's their task; All/super_admin → passes.
        $this->authorizeScopeItem(ScopeArea::Tasks, $task);
        $user = $request->user();

        if ($task->assigned_to !== $user->id && ! $user->isSuperAdmin()) {
            abort(403, 'You can only complete activities assigned to you.');
        }

        if ($task->status === 'complete') {
            return back();
        }

        DB::transaction(function () use ($task, $request): void {
            $task->update([
                'status' => 'complete',
                'completed_at' => now(),
            ]);

            $this->logActivity($request, 'task.quick_completed', $task, after: [
                'title' => $task->title,
            ]);
        });

        // Completed work leaves the calendar.
        $this->removeTaskFromGoogle($user, $task);

        return back()->with('success', 'Task completed.');
    }

    /**
     * One-click reschedule from the My Work list. The date input hands
     * back a bare YYYY-MM-DD; we pin it to 09:00 (matching reschedule())
     * so the list views don't read it as "due at midnight".
     */
    public function quickReschedule(int $id, Request $request): RedirectResponse
    {
        $task = Task::findOrFail($id);
        // Scope (phase 3b-ii) gates VISIBILITY before the ownership check
        // below gates MUTATION — the user must satisfy BOTH. None → 403;
        // Assigned → 403 unless it's their task; All/super_admin → passes.
        $this->authorizeScopeItem(ScopeArea::Tasks, $task);
        $user = $request->user();

        if ($task->created_by !== $user->id
            && $task->assigned_to !== $user->id
            && ! $user->isSuperAdmin()
        ) {
            abort(403, 'You can only reschedule tasks you own or are assigned to.');
        }

        $data = $request->validate([
            'due_at' => ['required', 'date'],
        ]);

        DB::transaction(function () use ($task, $data, $request): void {
            $task->update([
                'is_all_day' => true,
                'due_at' => Carbon::parse($data['due_at'])->setTime(9, 0),
                'start_at' => null,
                'end_at' => null,
            ]);

            $this->logActivity($request, 'task.rescheduled', $task, after: [
                'due_at' => $task->due_at?->toIso8601String(),
            ]);
        });

        $this->syncTaskToGoogle($user, $task);

        return back()->with('success', 'Task rescheduled.');
    }

    /**
     * Toggle the pinned flag. Pinned activities float to the top of
     * the customer timeline and are useful for "remember this" notes.
     */
    public function togglePin(int $id, Request $request): RedirectResponse
    {
        $task = Task::findOrFail($id);
        // Scope (phase 3b-ii) gates VISIBILITY before the ownership check
        // below gates MUTATION — the user must satisfy BOTH. None → 403;
        // Assigned → 403 unless it's their task; All/super_admin → passes.
        $this->authorizeScopeItem(ScopeArea::Tasks, $task);
        $user = $request->user();

        if ($task->created_by !== $user->id
            && $task->assigned_to !== $user->id
            && ! $user->isSuperAdmin()
        ) {
            abort(403);
        }

        $task->is_pinned = ! $task->is_pinned;
        $task->save();

        $this->logActivity($request, $task->is_pinned ? 'task.pinned' : 'task.unpinned', $task, after: ['title' => $task->title]);

        return back();
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $task = Task::findOrFail($id);
        // Scope (phase 3b-ii) gates VISIBILITY before the ownership check
        // below gates MUTATION — the user must satisfy BOTH. None → 403;
        // Assigned → 403 unless it's their task; All/super_admin → passes.
        $this->authorizeScopeItem(ScopeArea::Tasks, $task);
        $user = $request->user();

        if ($task->created_by !== $user->id && ! $user->isSuperAdmin()) {
            abort(403, 'Only the creator or a super_admin can delete an activity.');
        }

        $snapshot = ['title' => $task->title, 'type' => $task->type];
        $task->delete();

        $this->logActivity($request, 'task.deleted', $task, before: $snapshot);

        // $task still holds google_event_id in memory after delete().
        $this->removeTaskFromGoogle($user, $task);

        return back()->with('success', 'Activity deleted.');
    }

    /**
     * Attach a file to a task. The upload is routed through
     * FileUploadService (mandatory) which enforces the size limit, checks
     * the real bytes against the task_attachment MIME allow-list, strips
     * image EXIF, and stores under a UUID name on the private disk — the
     * client filename is kept only as display metadata, never as a path.
     */
    public function uploadAttachment(int $id, Request $request, FileUploadService $uploads): RedirectResponse
    {
        $task = Task::with('customer')->findOrFail($id);
        $user = $request->user();
        $this->authorizeTaskEdit($task, $user);

        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $clientMime = $file->getClientMimeType();
        $size = $file->getSize();

        // Throws a ValidationException on a disallowed type/oversize file,
        // which surfaces as a form error on `file` — no extra handling.
        $path = $uploads->store($file, 'task_attachment');

        DB::transaction(function () use ($task, $originalName, $clientMime, $size, $path, $user, $request) {
            $attachment = TaskAttachment::create([
                'task_id' => $task->id,
                'filename' => $originalName,
                'path' => $path,
                'mime_type' => $clientMime,
                'size_bytes' => $size,
                'uploaded_by' => $user->id,
            ]);

            $this->logActivity($request, 'task.attachment_uploaded', $task, after: [
                'attachment_id' => $attachment->id,
                'filename' => $attachment->filename,
                'size_bytes' => $attachment->size_bytes,
            ]);
        });

        return back()->with('success', 'Attachment uploaded.');
    }

    public function downloadAttachment(int $id, Request $request): StreamedResponse
    {
        $attachment = TaskAttachment::with('task.customer')->findOrFail($id);
        // Read access mirrors the detail page: customer-view for CRM tasks,
        // ownership-or-super_admin for orphans. Without this any signed-in
        // user could pull any file by id (IDOR).
        $this->authorizeTaskView($attachment->task, $request->user());

        abort_unless(
            Storage::disk('private')->exists($attachment->path),
            404,
            'File not found.',
        );

        return Storage::disk('private')->download($attachment->path, $attachment->filename);
    }

    public function destroyAttachment(int $id, Request $request, FileUploadService $uploads): RedirectResponse
    {
        $attachment = TaskAttachment::with('task.customer')->findOrFail($id);
        $task = $attachment->task;
        $this->authorizeTaskEdit($task, $request->user());

        $snapshot = ['attachment_id' => $attachment->id, 'filename' => $attachment->filename];
        $path = $attachment->path;

        // Drop the row inside the transaction, then delete the blob only
        // after the commit — a rolled-back delete must not orphan a live
        // row pointing at a file that's already gone.
        DB::transaction(function () use ($attachment, $task, $request, $snapshot) {
            $attachment->delete();
            $this->logActivity($request, 'task.attachment_deleted', $task, before: $snapshot);
        });

        $uploads->delete($path);

        return back()->with('success', 'Attachment removed.');
    }

    /**
     * View-level access to a task: customer-view gate for CRM tasks,
     * ownership-or-super_admin for orphan (customer_id null) tasks. Mirrors
     * the gate in show().
     */
    private function authorizeTaskView(Task $task, User $user): void
    {
        // Scope first (phase 3b-ii) — an attachment can't be pulled for a task
        // the user can't see. Composes with the per-subject gate below.
        $this->authorizeScopeItem(ScopeArea::Tasks, $task);

        if ($task->customer_id !== null) {
            Gate::authorize('view', $task->customer);
        } else {
            abort_unless(
                $task->assigned_to === $user->id
                    || $task->created_by === $user->id
                    || $user->isSuperAdmin(),
                403,
                'You do not have access to this activity.',
            );
        }
    }

    /**
     * Edit-level access: creator, assignee, or super_admin — the same rule
     * update() enforces, so attaching/removing files can't be done by
     * someone who couldn't edit the task itself.
     */
    private function authorizeTaskEdit(Task $task, User $user): void
    {
        // Scope first (phase 3b-ii): can't attach/remove files on a task
        // outside the user's scope. Composes with the ownership rule below.
        $this->authorizeScopeItem(ScopeArea::Tasks, $task);

        abort_unless(
            $task->created_by === $user->id
                || $task->assigned_to === $user->id
                || $user->isSuperAdmin(),
            403,
            'You can only modify activities you own or are assigned to.',
        );
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }

    /**
     * Coarse file-category key for the frontend icon map. Returns a plain
     * token (pdf/doc/xls/ppt/csv/image/zip/file) rather than a CSS class —
     * the UI renders @tabler/icons-vue components, there's no icon webfont.
     */
    private function mimeIcon(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'word'), str_contains($mime, 'document') => 'doc',
            str_contains($mime, 'sheet'), str_contains($mime, 'excel') => 'xls',
            str_contains($mime, 'presentation'), str_contains($mime, 'powerpoint') => 'ppt',
            str_contains($mime, 'csv') => 'csv',
            str_contains($mime, 'image') => 'image',
            str_contains($mime, 'zip') => 'zip',
            default => 'file',
        };
    }

    /**
     * PM-flavoured status transition. Distinct from complete() which
     * is the single-purpose "tick this off" handler used by the
     * activity feed checkbox — updateStatus is the generic kanban
     * column-change endpoint that the project board and the MyWork
     * status popover both call.
     *
     * Side effect: when a task hits 'complete' it triggers a
     * milestone-completion check so the milestone auto-rolls to
     * completed once every task is done. The operator can still
     * un-complete the milestone manually.
     */
    public function updateStatus(int $id, Request $request): JsonResponse|RedirectResponse
    {
        $task = Task::findOrFail($id);
        // Scope (phase 3b-ii) gates VISIBILITY before the ownership check
        // below gates MUTATION — the user must satisfy BOTH. None → 403;
        // Assigned → 403 unless it's their task; All/super_admin → passes.
        $this->authorizeScopeItem(ScopeArea::Tasks, $task);

        // Same access rule as complete(): assignee or super_admin.
        // PM workflows assume the assignee owns transitions; if you
        // need someone else to move the card, reassign first.
        $user = $request->user();
        if ($task->assigned_to !== $user->id
            && $task->created_by !== $user->id
            && ! $user->isSuperAdmin()) {
            abort(403, 'You can only change status on tasks you own or are assigned to.');
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['todo', 'in_progress', 'in_review', 'blocked', 'complete', 'cancelled'])],
            // Blocked requires a reason — surface why the work
            // stopped so it's actionable on the MyWork page.
            'blocked_reason' => 'required_if:status,blocked|nullable|string|max:500',
        ]);

        $oldStatus = $task->status;
        $completedMilestone = null;

        DB::transaction(function () use ($task, $data, &$completedMilestone) {
            $task->update([
                'status' => $data['status'],
                'completed_at' => $data['status'] === 'complete' ? now() : null,
                // Clear the blocked_reason when moving out of blocked
                // so a stale note doesn't sit on a now-active card.
                'blocked_reason' => $data['status'] === 'blocked'
                    ? ($data['blocked_reason'] ?? null)
                    : null,
            ]);

            if ($data['status'] === 'complete' && $task->milestone_id !== null) {
                $completedMilestone = $this->checkMilestoneCompletion($task->milestone_id);
            }
        });

        // Fan out the milestone-completed notification after the
        // transaction commits — keep the write transaction short and
        // don't hold it open across the recipient loop.
        if ($completedMilestone !== null) {
            app(NotificationService::class)->notifyMilestoneCompleted($completedMilestone);
        }

        // Terminal states leave the calendar; everything else stays in sync.
        if (in_array($data['status'], ['complete', 'cancelled'], true)) {
            $this->removeTaskFromGoogle($user, $task);
        } else {
            $this->syncTaskToGoogle($user, $task);
        }

        $this->logActivity($request, 'task.status_changed', $task, after: [
            'from' => $oldStatus,
            'to' => $data['status'],
        ]);

        // Kanban drag-drop calls this via raw fetch() — Inertia would
        // throw "valid Inertia response" on a back() redirect there.
        // Inertia POSTs (the timeline status quick-change) still get
        // a redirect because we want the toast.
        if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'ok' => true,
                'task_id' => $task->id,
                'status' => $task->status,
            ]);
        }

        return back()->with('success', 'Status updated.');
    }

    /**
     * Bulk reorder — called by the kanban drag-drop. Each item
     * carries its new sort_order and (in milestone mode) the
     * target milestone_id, so the same endpoint handles moves
     * within a column AND between columns.
     */
    public function reorderTasks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:tasks,id',
            'items.*.sort_order' => 'required|integer|min:0',
            'items.*.milestone_id' => 'nullable|integer|exists:milestones,id',
        ]);

        $taskIds = array_map(static fn (array $i): int => (int) $i['id'], $data['items']);

        // Scope (phase 3b-ii): the board only renders the user's in-scope
        // tasks, but a forged reorder could smuggle foreign ids — reject
        // unless EVERY id is in scope. None → empty set → 403; Assigned → 403
        // if any id isn't theirs; All/super_admin → passes.
        $inScopeCount = $this->scopeList(Task::whereIn('id', $taskIds), ScopeArea::Tasks)->count();
        abort_unless(
            $inScopeCount === count(array_unique($taskIds)),
            403,
            'You do not have access to one or more of these tasks.',
        );

        // IDOR fix: moving a task ONTO a different milestone is a project
        // mutation on the destination milestone's project. Require
        // projects.manage + Projects scope on that project — not just that the
        // milestone id exists. Without this, a Tasks-scoped non-member could
        // re-bucket a task onto (and cascade-complete) any project's milestone.
        // Unchanged milestone ids (within-column sort reorders) are skipped.
        $currentMilestones = Task::whereIn('id', $taskIds)->pluck('milestone_id', 'id');
        foreach ($data['items'] as $item) {
            if (! array_key_exists('milestone_id', $item) || $item['milestone_id'] === null) {
                continue;
            }
            if ((int) ($currentMilestones[$item['id']] ?? 0) === (int) $item['milestone_id']) {
                continue;
            }
            $dest = Milestone::find((int) $item['milestone_id']);
            abort_unless(
                $dest !== null && $this->canManageMilestoneProject($dest),
                403,
                'You cannot move a task onto a milestone in a project you do not manage.',
            );
        }

        // Milestones the moved tasks belonged to BEFORE the move — moving
        // the last open task out can leave the remainder all-complete.
        $affectedMilestoneIds = Task::whereIn('id', $taskIds)
            ->whereNotNull('milestone_id')
            ->pluck('milestone_id')
            ->all();

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $item) {
                Task::where('id', $item['id'])
                    ->update([
                        'sort_order' => $item['sort_order'],
                        // Keying with array_key_exists rather than
                        // ?? null lets the caller skip milestone_id
                        // entirely (status-board moves) without
                        // clobbering the existing milestone.
                        ...(array_key_exists('milestone_id', $item)
                            ? ['milestone_id' => $item['milestone_id']]
                            : []),
                    ]);
            }
        });

        // …plus the milestones they belong to AFTER the move (a completed
        // task dropped into an otherwise-done milestone completes it).
        $affectedMilestoneIds = array_values(array_unique(array_merge(
            $affectedMilestoneIds,
            Task::whereIn('id', $taskIds)->whereNotNull('milestone_id')->pluck('milestone_id')->all(),
        )));

        $completedMilestones = [];
        DB::transaction(function () use ($affectedMilestoneIds, &$completedMilestones) {
            foreach ($affectedMilestoneIds as $milestoneId) {
                $milestone = $this->checkMilestoneCompletion((int) $milestoneId);
                if ($milestone !== null) {
                    $completedMilestones[] = $milestone;
                }
            }
        });

        foreach ($completedMilestones as $milestone) {
            app(NotificationService::class)->notifyMilestoneCompleted($milestone);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Whether the acting user may MUTATE the given milestone's project — holds
     * projects.manage AND has Projects-scope access to that project. Gates the
     * cross-section milestone side-effects (cascade-completion + reorder
     * re-bucket) reached from the tasks controller, where the project route
     * middleware doesn't apply. super_admin passes via Gate::before through can().
     */
    private function canManageProject(?Project $project): bool
    {
        $user = auth()->user();

        return $project !== null && $user !== null
            && $user->can('projects.manage')
            && ScopeEnforcer::allows($user, ScopeArea::Projects, $project);
    }

    private function canManageMilestoneProject(Milestone $milestone): bool
    {
        return $this->canManageProject(Project::find($milestone->project_id));
    }

    /**
     * Roll the parent milestone to completed when every one of its
     * tasks is done. Idempotent — calling this on an already-completed
     * milestone is a no-op. Returns the milestone ONLY when this call
     * is the one that completed it, so the caller can fire the
     * milestone-completed notification exactly once.
     */
    private function checkMilestoneCompletion(int $milestoneId): ?Milestone
    {
        $milestone = Milestone::findOrFail($milestoneId);

        // Auto-completing a milestone is a project mutation: only cascade it when
        // the acting user can manage the milestone's project (projects.manage +
        // Projects scope). A task-assignee on a project they don't manage still
        // completes their own task, but does NOT cascade-complete that project's
        // milestone — closing the cross-section escalation. super_admin passes.
        if (! $this->canManageMilestoneProject($milestone)) {
            return null;
        }

        $total = Task::where('milestone_id', $milestoneId)->count();
        $done = Task::where('milestone_id', $milestoneId)
            ->where('status', 'complete')
            ->count();

        if ($total > 0 && $total === $done && $milestone->status !== 'completed') {
            $milestone->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return $milestone;
        }

        return null;
    }

    /**
     * Shared validation rules.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $forUpdate = false): array
    {
        return [
            'type' => ['required', Rule::in(self::TYPES)],
            // For tasks/calls/meetings a title is required; for notes
            // and emails it's allowed to be blank and we'll fall back
            // to a sensible default.
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::in(self::PRIORITIES)],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            // Optional CRM subject — a task may hang off a lead OR a
            // customer (guardSingleSubject rejects both at once), or
            // neither (a standalone task). Listed here for the same
            // reason as project_id below: an unvalidated key is
            // silently stripped and the association written NULL —
            // exactly the bug that orphaned every lead-page activity.
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'parent_task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            // Due-date policy lives in one place — App\Support\TaskDueDate —
            // so this surface can never drift from the support / customer
            // creators. (Actionable types require it; note/email + timed
            // events are exempt.)
            'due_at' => TaskDueDate::rule(),
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            // PM Sprint 1 fields. Validation lists them so
            // $request->validate() doesn't strip them from $data —
            // without these, the kanban quick-add POSTs a project_id
            // and the controller silently writes NULL, hiding the
            // task from the project it was meant to belong to.
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'milestone_id' => ['nullable', 'integer', 'exists:milestones,id'],
            // Calendar fields. is_all_day=false promotes the task to a
            // timed event (meeting) that carries a real start/end slot.
            'is_all_day' => ['nullable', 'boolean'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return TaskDueDate::messages();
    }

    /**
     * Resolve the calendar-scheduling columns from request data. An
     * all-day task (the default) has no timed slot; a timed event keeps
     * start_at/end_at and mirrors start_at into due_at so the existing
     * due-date surfaces (MyWork list, overdue logic) stay consistent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scheduleAttributes(array $data): array
    {
        // Notes never carry a schedule.
        if (($data['type'] ?? null) === 'note') {
            return ['is_all_day' => true, 'start_at' => null, 'end_at' => null, 'location' => $data['location'] ?? null];
        }

        $isAllDay = ! array_key_exists('is_all_day', $data) || (bool) $data['is_all_day'];

        if ($isAllDay) {
            return [
                'is_all_day' => true,
                'start_at' => null,
                'end_at' => null,
                'location' => $data['location'] ?? null,
            ];
        }

        $start = ! empty($data['start_at']) ? Carbon::parse($data['start_at']) : null;
        $end = ! empty($data['end_at']) ? Carbon::parse($data['end_at']) : $start?->copy()->addHour();

        return [
            'is_all_day' => false,
            'start_at' => $start,
            'end_at' => $end,
            // Keep due_at aligned with the event start for the list views.
            'due_at' => $start,
            'location' => $data['location'] ?? null,
        ];
    }

    /**
     * Block a forged contact_id that points at a contact owned by a
     * different customer. The Rule\Exists check confirms the contact
     * exists at all; this confirms it belongs to the activity's
     * customer (if one was supplied).
     *
     * @param  array<string, mixed>  $data
     */
    private function guardContactBelongsToCustomer(array $data): void
    {
        if (empty($data['contact_id']) || empty($data['customer_id'])) {
            return;
        }

        $matches = Contact::where('id', $data['contact_id'])
            ->where('customer_id', $data['customer_id'])
            ->exists();

        abort_unless($matches, 422, 'Selected contact does not belong to the chosen customer.');
    }

    /**
     * Resolve the task's optional CRM subject (lead OR customer).
     *
     * Both associations are optional — a task with neither is a valid
     * standalone task. A key absent from the request keeps the task's
     * current value (update semantics); an explicit null clears it.
     * What is never valid is BOTH set at once: one subject or none.
     *
     * @return array{0: int|null, 1: int|null} [lead_id, customer_id]
     */
    private function guardSingleSubject(array $data, ?Task $task = null): array
    {
        $leadId = array_key_exists('lead_id', $data) ? $data['lead_id'] : $task?->lead_id;
        $customerId = array_key_exists('customer_id', $data) ? $data['customer_id'] : $task?->customer_id;

        abort_if(
            ! empty($leadId) && ! empty($customerId),
            422,
            'Link an activity to a lead or a customer — not both.',
        );

        return [$leadId, $customerId];
    }

    /**
     * Leads scope (phase 3b-iii): a task may only be linked to a lead the
     * current user can see. The lead_id validation rule is exists-only, so
     * without this an Assigned-scope user could forge a link to an out-of-scope
     * lead and then read its identity back via the task's lead eager-load.
     * No-op for All / super_admin (allows() short-circuits).
     */
    private function guardLeadInScope(?int $leadId): void
    {
        if ($leadId === null) {
            return;
        }

        $this->authorizeScopeItem(ScopeArea::Leads, Lead::findOrFail($leadId));
    }

    /**
     * Sensible default title for the types where title is optional.
     */
    private function fallbackTitle(string $type): string
    {
        return match ($type) {
            'note' => 'Note',
            'email' => 'Email',
            'call' => 'Call',
            'meeting' => 'Meeting',
            default => 'Task',
        };
    }

    private function createdMessage(Task $task): string
    {
        return match ($task->type) {
            'call' => 'Call logged.',
            'email' => 'Email logged.',
            'meeting' => 'Meeting scheduled.',
            'note' => 'Note added.',
            default => "Task created: {$task->title}",
        };
    }

    /**
     * Mirror a scheduled task into the acting user's Google Calendar
     * (create on first sync, update thereafter). No-ops for users who
     * haven't connected Google and for tasks with no due_at (notes).
     * The service swallows its own API errors, so a Google outage can
     * never break the task write that triggered this.
     */
    private function syncTaskToGoogle(User $user, Task $task): void
    {
        if (! $user->google_sync_enabled || $user->google_access_token === null || $task->due_at === null) {
            return;
        }

        $gcal = new GoogleCalendarService($user);

        if ($task->google_event_id === null) {
            $eventId = $gcal->createEvent($task);
            if ($eventId !== null) {
                $task->update(['google_event_id' => $eventId]);
            }
        } else {
            $gcal->updateEvent($task);
        }
    }

    /**
     * Drop a task's mirrored Google event (on completion/cancellation/
     * deletion) so a done task doesn't linger on the calendar.
     */
    private function removeTaskFromGoogle(User $user, Task $task): void
    {
        if ($task->google_event_id === null
            || ! $user->google_sync_enabled
            || $user->google_access_token === null
        ) {
            return;
        }

        (new GoogleCalendarService($user))->deleteEvent($task->google_event_id);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function logActivity(
        Request $request,
        string $action,
        Task $task,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => $action,
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
