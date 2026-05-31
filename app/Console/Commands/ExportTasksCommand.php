<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsPowerhouseConfig;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Export a Powerhouse project's current task state to SPRINT-STATUS.md.
 * Run this before starting a Claude Code session so the agent knows what
 * is already done, in progress, or blocked — and has a task-id map to
 * reference when it calls task:update.
 */
class ExportTasksCommand extends Command
{
    use ReadsPowerhouseConfig;

    /** @var string */
    protected $signature = 'task:export
        {--project= : Powerhouse project ID (overrides .powerhouse.json)}
        {--output= : Output file (default: <project>/SPRINT-STATUS.md)}';

    /** @var string */
    protected $description = 'Export the current Powerhouse project task state to SPRINT-STATUS.md.';

    public function handle(): int
    {
        $projectIdRaw = $this->option('project') ?: $this->readPowerhouseJson('project_id');
        if (! $projectIdRaw) {
            $this->error('No project_id found. Set it in .powerhouse.json or pass --project=N.');

            return self::FAILURE;
        }
        $projectId = (int) $projectIdRaw;

        $project = Project::with([
            'milestones' => fn ($q) => $q->orderBy('sort_order')
                ->with(['tasks' => fn ($q2) => $q2->orderBy('sort_order')->with('assignedTo:id,name')]),
            'tasks' => fn ($q) => $q->whereNull('milestone_id')
                ->orderBy('sort_order')
                ->with('assignedTo:id,name'),
        ])->find($projectId);

        if (! $project) {
            $this->error("Project #{$projectId} not found in Powerhouse.");

            return self::FAILURE;
        }

        $statusIcon = fn (string $status): string => match ($status) {
            'complete' => '✅',
            'in_progress' => '⏳',
            'in_review' => '👀',
            'blocked' => '🔴',
            'cancelled' => '❌',
            default => '⬜',
        };

        // Every task across milestones + the loose (no-milestone) set.
        /** @var Collection<int, Task> $allTasks */
        $allTasks = $project->milestones
            ->flatMap(fn ($m) => $m->tasks)
            ->concat($project->tasks);

        $counts = $allTasks->groupBy('status')->map->count();

        $lines = [];
        $lines[] = '# Sprint Status: '.$project->title;
        $lines[] = 'Generated: '.now()->format('d M Y H:i').' UTC';
        $lines[] = 'Project ID: '.$projectId;
        $lines[] = '';

        $lines[] = '## Summary';
        $lines[] = '- ✅ Complete: '.($counts['complete'] ?? 0);
        $lines[] = '- ⏳ In progress: '.($counts['in_progress'] ?? 0);
        $lines[] = '- 👀 In review: '.($counts['in_review'] ?? 0);
        $lines[] = '- 🔴 Blocked: '.($counts['blocked'] ?? 0);
        $lines[] = '- ⬜ To do: '.($counts['todo'] ?? 0);
        $lines[] = '';

        foreach ($project->milestones as $milestone) {
            $lines[] = '## Milestone: '.$milestone->title;
            foreach ($milestone->tasks as $task) {
                $who = $task->assignedTo ? $task->assignedTo->name : '—';
                $blocked = $task->status === 'blocked'
                    ? ' [BLOCKED: '.($task->blocked_reason ?? 'no reason').']'
                    : '';
                $lines[] = $statusIcon($task->status).' '.$task->title.' (assigned: '.$who.')'.$blocked;
            }
            $lines[] = '';
        }

        if ($project->tasks->isNotEmpty()) {
            $lines[] = '## No milestone';
            foreach ($project->tasks as $task) {
                $lines[] = $statusIcon($task->status).' '.$task->title;
            }
            $lines[] = '';
        }

        // Id map so Claude Code can resolve a title to the real task id.
        $lines[] = '## Task ID Map';
        $lines[] = '<!-- Claude Code: use these IDs with the task:update command -->';
        foreach ($allTasks as $task) {
            $lines[] = $task->id.': '.$task->title;
        }
        $lines[] = '';

        $output = $this->option('output') ?: base_path('SPRINT-STATUS.md');
        file_put_contents($output, implode("\n", $lines));

        $this->info('Exported to: '.$output);
        $this->line('Tasks: '.$allTasks->count().
            ' ('.($counts['complete'] ?? 0).' complete, '.($counts['todo'] ?? 0).' to do)');

        return self::SUCCESS;
    }
}
