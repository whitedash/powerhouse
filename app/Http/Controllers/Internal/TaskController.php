<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    private const TYPES = ['task', 'call', 'email', 'meeting', 'note'];

    private const PRIORITIES = ['low', 'medium', 'high'];

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
                'assigned_to' => $data['assigned_to'] ?? $userId,
                'created_by' => $userId,
                'status' => 'open',
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
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
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
