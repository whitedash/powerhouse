<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'due_date' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $userId = $request->user()->id;

        $task = DB::transaction(function () use ($data, $request, $userId) {
            $task = Task::create([
                'title' => $data['title'],
                'due_date' => $data['due_date'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? $userId,
                'created_by' => $userId,
                'status' => 'open',
            ]);

            $this->logActivity($request, 'task.created', $task, after: [
                'title' => $task->title,
            ]);

            return $task;
        });

        return back()->with('success', "Task created: {$task->title}");
    }

    public function complete(int $id, Request $request): RedirectResponse
    {
        $task = Task::findOrFail($id);

        // Only the assignee or a super_admin can mark a task complete.
        // Staff editing somebody else's task would mask ownership, and
        // task-completion telemetry needs to reflect who actually did
        // the work.
        $user = $request->user();
        if ($task->assigned_to !== $user->id && ! $user->isSuperAdmin()) {
            abort(403, 'You can only complete tasks assigned to you.');
        }

        if ($task->status === 'complete') {
            return back();
        }

        DB::transaction(function () use ($task, $request) {
            $task->update([
                'status' => 'complete',
                'completed_at' => now(),
            ]);

            $this->logActivity($request, 'task.completed', $task, after: [
                'title' => $task->title,
            ]);
        });

        return back()->with('success', 'Task completed.');
    }

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
