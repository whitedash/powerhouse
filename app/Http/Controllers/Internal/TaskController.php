<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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
            'contact:id,customer_id,name,email,phone,job_title',
            'assignedTo:id,name,avatar_colour,role',
            'createdBy:id,name',
            'parentTask:id,title,type',
            'notes' => fn ($q) => $q->orderBy('created_at')
                ->with('author:id,name,avatar_colour'),
            'childTasks' => fn ($q) => $q->with('assignedTo:id,name,avatar_colour'),
        ])->findOrFail($id);

        // Authorisation. Tasks attached to a customer ride the
        // CustomerPolicy::view check; orphan tasks (customer_id null)
        // fall back to ownership-or-super_admin so a staffer can't
        // browse another staffer's private TODO list.
        $user = $request->user();
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

        // Other open activities on the same customer — gives the
        // operator a one-click jump to anything else they need to
        // chase on this account.
        $related = $task->customer_id
            ? Task::where('customer_id', $task->customer_id)
                ->where('id', '!=', $task->id)
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
                'customer_id' => $task->customer_id,
                'customer' => $task->customer ? [
                    'id' => $task->customer->id,
                    'name' => $task->customer->name,
                    'city' => $task->customer->city,
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
            // me lets the frontend default the "Assign to" picker in
            // the linked-task slide-over without an extra fetch.
            'me_id' => $user->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $userId = $request->user()->id;
        $this->guardContactBelongsToCustomer($data);

        $task = DB::transaction(function () use ($data, $request, $userId) {
            $task = Task::create([
                'type' => $data['type'],
                'title' => $data['title'] ?? $this->fallbackTitle($data['type']),
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
                'customer_id' => $data['customer_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
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
                // Notes are open-ended by design — no schedule.
                'due_at' => $data['type'] === 'note' ? null : $this->parseDueAt($data['due_at'] ?? null),
                'duration_minutes' => $data['duration_minutes'] ?? null,
            ]);

            $this->logActivity($request, 'task.created', $task, after: [
                'title' => $task->title,
                'type' => $task->type,
            ]);

            return $task;
        });

        return back()->with('success', $this->createdMessage($task));
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $task = Task::findOrFail($id);
        $user = $request->user();

        // Editable by the creator, the assignee, or a super_admin. Anyone
        // else editing somebody else's activity would mask ownership.
        if ($task->created_by !== $user->id
            && $task->assigned_to !== $user->id
            && ! $user->isSuperAdmin()
        ) {
            abort(403, 'You can only edit activities you own or are assigned to.');
        }

        $data = $request->validate($this->rules(forUpdate: true));
        $this->guardContactBelongsToCustomer($data);

        $before = $task->only(['title', 'type', 'priority', 'description', 'due_at', 'duration_minutes']);

        DB::transaction(function () use ($task, $data) {
            $task->fill([
                'type' => $data['type'],
                'title' => $data['title'] ?? $this->fallbackTitle($data['type']),
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'] ?? $task->priority,
                'customer_id' => $data['customer_id'] ?? $task->customer_id,
                'contact_id' => $data['contact_id'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? $task->assigned_to,
                'due_at' => $data['type'] === 'note' ? null : $this->parseDueAt($data['due_at'] ?? null),
                'duration_minutes' => $data['duration_minutes'] ?? null,
            ])->save();
        });

        $this->logActivity($request, 'task.updated', $task, before: $before, after: $task->only(['title', 'type', 'priority', 'description', 'due_at', 'duration_minutes']));

        return back()->with('success', 'Activity updated.');
    }

    public function complete(int $id, Request $request): RedirectResponse
    {
        $task = Task::findOrFail($id);

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

        return back()->with('success', 'Activity completed.');
    }

    /**
     * Toggle the pinned flag. Pinned activities float to the top of
     * the customer timeline and are useful for "remember this" notes.
     */
    public function togglePin(int $id, Request $request): RedirectResponse
    {
        $task = Task::findOrFail($id);
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
        $user = $request->user();

        if ($task->created_by !== $user->id && ! $user->isSuperAdmin()) {
            abort(403, 'Only the creator or a super_admin can delete an activity.');
        }

        $snapshot = ['title' => $task->title, 'type' => $task->type];
        $task->delete();

        $this->logActivity($request, 'task.deleted', $task, before: $snapshot);

        return back()->with('success', 'Activity deleted.');
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
    public function updateStatus(int $id, Request $request): RedirectResponse
    {
        $task = Task::findOrFail($id);

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

        DB::transaction(function () use ($task, $data) {
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
                $this->checkMilestoneCompletion($task->milestone_id);
            }
        });

        $this->logActivity($request, 'task.status_changed', $task, after: [
            'from' => $oldStatus,
            'to' => $data['status'],
        ]);

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

        return response()->json(['ok' => true]);
    }

    /**
     * Roll the parent milestone to completed when every one of its
     * tasks is done. Idempotent — calling this on an already-
     * completed milestone is a no-op.
     */
    private function checkMilestoneCompletion(int $milestoneId): void
    {
        $milestone = Milestone::findOrFail($milestoneId);
        $total = Task::where('milestone_id', $milestoneId)->count();
        $done = Task::where('milestone_id', $milestoneId)
            ->where('status', 'complete')
            ->count();

        if ($total > 0 && $total === $done && $milestone->status !== 'completed') {
            $milestone->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
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
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'parent_task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            // PM Sprint 1 fields. Validation lists them so
            // $request->validate() doesn't strip them from $data —
            // without these, the kanban quick-add POSTs a project_id
            // and the controller silently writes NULL, hiding the
            // task from the project it was meant to belong to.
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'milestone_id' => ['nullable', 'integer', 'exists:milestones,id'],
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
     * Coerce a date-string-or-datetime into a Carbon instance. A bare
     * "2026-06-10" lands at 09:00 local time (a reasonable working-day
     * slot); anything carrying a time component is kept verbatim.
     */
    private function parseDueAt(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        $parsed = Carbon::parse($value);

        // Bare YYYY-MM-DD parses at midnight — bump it to 09:00 so the
        // schedule isn't "due at start of day" which feels off for any
        // human-facing surface.
        if ($parsed->isStartOfDay() && ! str_contains($value, ':')) {
            $parsed->setTime(9, 0, 0);
        }

        return $parsed;
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
