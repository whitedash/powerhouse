<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Milestone;
use App\Models\Note;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Update a Powerhouse task's status from the command line. Claude Code
 * calls this as it works through a sprint (in_progress when it starts a
 * task, complete when it finishes, blocked --reason when stuck), so the
 * Powerhouse kanban mirrors what the agent is actually doing.
 */
class UpdateTaskCommand extends Command
{
    /** @var string */
    protected $signature = 'task:update
        {id : Task ID from Powerhouse}
        {status : New status (todo|in_progress|in_review|blocked|complete|cancelled)}
        {--reason= : Required when setting status to blocked}
        {--note= : Optional progress note attached to the task}';

    /** @var string */
    protected $description = 'Update a Powerhouse task status from the command line.';

    public function handle(): int
    {
        $task = Task::find((int) $this->argument('id'));
        if (! $task) {
            $this->error('Task #'.$this->argument('id').' not found in Powerhouse.');

            return self::FAILURE;
        }

        $status = $this->argument('status');
        $validStatuses = ['todo', 'in_progress', 'in_review', 'blocked', 'complete', 'cancelled'];

        if (! in_array($status, $validStatuses, true)) {
            $this->error('Invalid status. Use: '.implode(', ', $validStatuses));

            return self::FAILURE;
        }

        if ($status === 'blocked' && ! $this->option('reason')) {
            $this->error('--reason is required when blocking a task.');

            return self::FAILURE;
        }

        $from = $task->status;

        DB::transaction(function () use ($task, $status, $from) {
            $task->update([
                'status' => $status,
                // Only carry a blocked_reason while the task is blocked; any
                // other transition clears a stale one.
                'blocked_reason' => $status === 'blocked' ? $this->option('reason') : null,
                'completed_at' => $status === 'complete' ? now() : null,
            ]);

            ActivityLog::create([
                'user_id' => null,
                'user_role' => 'system',
                'action' => 'task.status_changed',
                'entity_type' => 'task',
                'entity_id' => $task->id,
                'before' => ['status' => $from],
                'after' => ['status' => $status, 'via' => 'task:update'],
            ]);

            // Attach a progress note, scoped to the task (task_id) so it
            // shows on the task thread. notes.customer_id is NOT NULL, so we
            // fall back to the task's project customer; an internal project
            // with no customer can't carry a note — skip it with a warning
            // rather than crash the status update.
            if ($note = $this->option('note')) {
                $customerId = $task->customer_id;
                if ($customerId === null && $task->project) {
                    $customerId = $task->project->customer_id;
                }
                if ($customerId) {
                    Note::create([
                        'task_id' => $task->id,
                        'customer_id' => $customerId,
                        'body' => '[Claude Code] '.$note,
                        'created_by' => 1,
                    ]);
                } else {
                    $this->warn('Note skipped: task has no associated customer (notes require one).');
                }
            }
        });

        // Auto-complete the parent milestone once every task under it is
        // complete, so finishing the last task closes the milestone too.
        if ($status === 'complete' && $task->milestone_id) {
            $total = Task::where('milestone_id', $task->milestone_id)->count();
            $done = Task::where('milestone_id', $task->milestone_id)
                ->where('status', 'complete')
                ->count();

            if ($total > 0 && $total === $done) {
                $milestone = Milestone::find($task->milestone_id);
                if ($milestone && $milestone->status !== 'completed') {
                    DB::transaction(function () use ($milestone) {
                        $milestone->update(['status' => 'completed', 'completed_at' => now()]);

                        ActivityLog::create([
                            'user_id' => null,
                            'user_role' => 'system',
                            'action' => 'milestone.completed',
                            'entity_type' => 'milestone',
                            'entity_id' => $milestone->id,
                            'before' => ['status' => 'in_progress'],
                            'after' => ['status' => 'completed', 'via' => 'task:update'],
                        ]);
                    });
                    $this->info('Milestone completed!');
                }
            }
        }

        $this->info('Task #'.$task->id.' → '.$status);

        return self::SUCCESS;
    }
}
