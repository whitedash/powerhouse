<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDueSoon;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily sweep for the two time-based notification triggers:
 *
 *   • Projects whose due_date has passed while still active → notify
 *     the project lead (ProjectOverdue).
 *   • Tasks falling due within the next 24h → notify the assignee
 *     (TaskDueSoon).
 *
 * Both are guarded by a "already sent today" check against the
 * notifications table (type + data->entity_id + created_at >= start of
 * day), so a project that stays overdue for a week pings the lead once
 * per day, not every run.
 */
class CheckOverdueProjects extends Command
{
    /** @var string */
    protected $signature = 'notifications:check-overdue';

    /** @var string */
    protected $description = 'Notify on overdue projects and tasks due within 24h (once per day each).';

    public function handle(NotificationService $notifications): int
    {
        $this->checkOverdueProjects($notifications);
        $this->checkTasksDueSoon();

        return self::SUCCESS;
    }

    private function checkOverdueProjects(NotificationService $notifications): void
    {
        $projects = Project::query()
            ->where('status', 'active')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereNull('archived_at')
            ->get();

        $sent = 0;
        foreach ($projects as $project) {
            if ($this->alreadyNotifiedToday('ProjectOverdue', $project->id)) {
                continue;
            }
            $notifications->notifyProjectOverdue($project);
            $sent++;
        }

        $this->line("Overdue projects: {$projects->count()} found, {$sent} notified.");
    }

    private function checkTasksDueSoon(): void
    {
        $tasks = Task::query()
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addDay()])
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->whereNotNull('assigned_to')
            ->get();

        $sent = 0;
        foreach ($tasks as $task) {
            if ($this->alreadyNotifiedToday('TaskDueSoon', $task->id)) {
                continue;
            }

            $user = User::find($task->assigned_to);
            if (! $user || ! $user->wantsNotification('task_due_soon')) {
                continue;
            }

            $user->notify(new TaskDueSoon($task));
            $sent++;
        }

        $this->line("Tasks due soon: {$tasks->count()} found, {$sent} notified.");
    }

    /**
     * Has a notification of the given class for this entity already been
     * written since midnight? The notifications.type column holds the
     * full class name, so a LIKE on the short name is enough to scope it.
     */
    private function alreadyNotifiedToday(string $notificationClass, int $entityId): bool
    {
        return DB::table('notifications')
            ->where('type', 'like', '%'.$notificationClass)
            ->where('data->entity_id', $entityId)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();
    }
}
