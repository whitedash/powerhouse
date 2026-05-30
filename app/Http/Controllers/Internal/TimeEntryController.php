<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Time entries — the billable raw material that ProjectController
 * later rolls into invoices. Three rules worth flagging:
 *
 *  1) project_id is denormalised from task_id. We always look it up
 *     from the parent task at create time so the project filter on
 *     the Time tab can run a single-table query.
 *  2) Once an entry has invoice_id set, it's frozen — neither the
 *     author nor a super_admin can edit or delete it without first
 *     voiding the invoice. Otherwise the historical invoice would
 *     no longer match the billed work.
 *  3) Edits are author-only unless the operator is super_admin, on
 *     the same principle as activity notes: you don't get to
 *     rewrite someone else's billable time.
 */
class TimeEntryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $data = $request->validate([
            'task_id' => 'required|integer|exists:tasks,id',
            // 24h max per entry — anything longer is operator error.
            'minutes' => 'required|integer|min:1|max:1440',
            'description' => 'nullable|string|max:1000',
            'logged_at' => 'required|date',
            'is_billable' => 'nullable|boolean',
            'hourly_rate' => 'nullable|numeric|min:0',
        ]);

        $task = Task::findOrFail($data['task_id']);

        if ($task->project_id === null) {
            return back()->with('error', 'Time can only be logged on tasks that belong to a project.');
        }

        $entry = TimeEntry::create([
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'user_id' => $request->user()->id,
            'minutes' => $data['minutes'],
            'description' => $data['description'] ?? null,
            'logged_at' => $data['logged_at'],
            'is_billable' => $data['is_billable'] ?? true,
            'hourly_rate' => $data['hourly_rate'] ?? null,
        ]);

        $this->log($request, 'time_entry.created', $entry, after: [
            'task_id' => $entry->task_id,
            'minutes' => $entry->minutes,
            'is_billable' => $entry->is_billable,
        ]);

        return back()->with('success', 'Time logged.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $entry = TimeEntry::findOrFail($id);
        $user = $request->user();

        if ($entry->user_id !== $user->id && ! $user->isSuperAdmin()) {
            abort(403, 'You can only edit time entries you logged yourself.');
        }

        if ($entry->invoice_id !== null) {
            return back()->with('error', 'This time entry has been invoiced and cannot be edited. Void the invoice first.');
        }

        $data = $request->validate([
            'minutes' => 'required|integer|min:1|max:1440',
            'description' => 'nullable|string|max:1000',
            'logged_at' => 'required|date',
            'is_billable' => 'nullable|boolean',
            'hourly_rate' => 'nullable|numeric|min:0',
        ]);

        $before = $entry->only(['minutes', 'is_billable', 'logged_at']);
        $entry->update($data + ['is_billable' => $data['is_billable'] ?? $entry->is_billable]);

        $this->log($request, 'time_entry.updated', $entry, before: $before, after: $entry->only(['minutes', 'is_billable', 'logged_at']));

        return back()->with('success', 'Time entry updated.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $entry = TimeEntry::findOrFail($id);
        $user = $request->user();

        if ($entry->user_id !== $user->id && ! $user->isSuperAdmin()) {
            abort(403, 'You can only delete time entries you logged yourself.');
        }

        if ($entry->invoice_id !== null) {
            return back()->with('error', 'This time entry has been invoiced. Void the invoice first.');
        }

        DB::transaction(function () use ($entry, $request) {
            $snapshot = [
                'task_id' => $entry->task_id,
                'minutes' => $entry->minutes,
            ];
            $entry->delete();
            $this->log($request, 'time_entry.deleted', $entry, before: $snapshot);
        });

        return back()->with('success', 'Time entry removed.');
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Request $request, string $action, TimeEntry $entry, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => $action,
            // Logs attach to the project so they show up on the
            // project Show Activity tab without an extra join.
            'entity_type' => 'project',
            'entity_id' => $entry->project_id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
