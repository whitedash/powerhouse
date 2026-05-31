<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsPowerhouseConfig;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Quick terminal overview of a project's task state, grouped by status.
 * Claude Code runs this at session start to know what is already done
 * before it picks up work — a task showing complete is skipped, not redone.
 */
class TaskStatusCommand extends Command
{
    use ReadsPowerhouseConfig;

    /** @var string */
    protected $signature = 'task:status
        {--project= : Powerhouse project ID (overrides .powerhouse.json)}';

    /** @var string */
    protected $description = 'Quick terminal overview of a Powerhouse project task state.';

    public function handle(): int
    {
        $projectIdRaw = $this->option('project') ?: $this->readPowerhouseJson('project_id');
        if (! $projectIdRaw) {
            $this->error('No project_id found. Set it in .powerhouse.json or pass --project=N.');

            return self::FAILURE;
        }
        $projectId = (int) $projectIdRaw;

        $project = Project::with([
            'milestones.tasks',
            'tasks' => fn ($q) => $q->whereNull('milestone_id'),
        ])->find($projectId);

        if (! $project) {
            $this->error("Project #{$projectId} not found in Powerhouse.");

            return self::FAILURE;
        }

        $this->info('Project: '.$project->title);
        $this->newLine();

        /** @var Collection<int, Task> $allTasks */
        $allTasks = $project->milestones
            ->flatMap(fn ($m) => $m->tasks)
            ->concat($project->tasks);

        if ($allTasks->isEmpty()) {
            $this->line('No tasks on this project yet.');

            return self::SUCCESS;
        }

        foreach (['complete', 'in_progress', 'in_review', 'blocked', 'todo'] as $s) {
            $tasks = $allTasks->where('status', $s);
            if ($tasks->isEmpty()) {
                continue;
            }

            $colour = match ($s) {
                'complete' => 'green',
                'in_progress' => 'yellow',
                'in_review' => 'cyan',
                'blocked' => 'red',
                default => 'white',
            };

            $icon = match ($s) {
                'complete' => '✅',
                'in_progress' => '⏳',
                'in_review' => '👀',
                'blocked' => '🔴',
                default => '⬜',
            };

            $this->line("<fg={$colour}>".$icon.' '.strtoupper($s).' ('.$tasks->count().')</>');
            foreach ($tasks as $task) {
                $blocked = $task->blocked_reason ? ' — '.$task->blocked_reason : '';
                $this->line('  #'.$task->id.' '.$task->title.$blocked);
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
