<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsPowerhouseConfig;
use App\Models\ActivityLog;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Create Powerhouse tasks from a TASKS.md sprint file written for a Claude
 * Code session. The sync is idempotent: tasks already present on the
 * project (matched case-insensitively by title) are skipped, so it is safe
 * to re-run after editing TASKS.md. Created task ids are written to
 * .claude/sprint-tasks.json so Claude Code can later call task:update.
 */
class SyncTasksCommand extends Command
{
    use ReadsPowerhouseConfig;

    /** @var string */
    protected $signature = 'task:sync
        {file? : Path to the TASKS.md sprint file (default: <project>/TASKS.md)}
        {--project= : Powerhouse project ID (overrides .powerhouse.json)}
        {--milestone= : Milestone name to find or create}
        {--dry-run : Preview what would be created without writing anything}';

    /** @var string */
    protected $description = 'Create Powerhouse tasks from a TASKS.md sprint file (skips tasks that already exist, matched by title).';

    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(<<<'HELP'
            Reads a TASKS.md sprint file and creates the listed tasks on a
            Powerhouse project. Existing tasks (matched by title) are skipped.

            TASKS.md format:

              # Sprint: {sprint name}
              project_id: {id}
              milestone: {milestone name}

              ## Tasks
              - [ ] Task title here
                    type: task | priority: high | hours: 8
              - [ ] Another task
                    type: task | priority: medium | hours: 4

              ## Notes
              Anything below the task list (a Notes section, or a `---`
              rule) is ignored by the sync.

            Valid types: task, call, email, meeting, note
            Valid priorities: low, medium, high, urgent

            The project id is resolved from --project, then `project_id` in
            .powerhouse.json. The milestone from --milestone, then the file's
            `milestone:` line, then `active_milestone` in .powerhouse.json;
            it is created on the project if it does not already exist.
            HELP);
    }

    public function handle(): int
    {
        // 1. Resolve the project — never guess an id.
        $projectIdRaw = $this->option('project') ?: $this->readPowerhouseJson('project_id');
        if (! $projectIdRaw) {
            $this->error('No project_id found. Set it in .powerhouse.json or pass --project=N.');

            return self::FAILURE;
        }
        $projectId = (int) $projectIdRaw;

        $project = Project::find($projectId);
        if (! $project) {
            $this->error("Project #{$projectId} not found in Powerhouse.");

            return self::FAILURE;
        }

        // 2. Locate + parse the sprint file.
        $file = $this->argument('file') ?: base_path('TASKS.md');
        if (! is_file($file)) {
            $this->error("TASKS.md not found at: {$file}");

            return self::FAILURE;
        }

        $parsed = $this->parseTasksFile((string) file_get_contents($file));
        if (empty($parsed['tasks'])) {
            $this->warn("No tasks found in {$file}. Nothing to sync.");

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info("Syncing into project #{$projectId} — {$project->title}");

        // 3. Resolve or create the milestone.
        $milestoneName = $this->option('milestone')
            ?: $parsed['milestone']
            ?? $this->readPowerhouseJson('active_milestone');

        $milestoneId = null;
        if ($milestoneName) {
            $milestone = Milestone::where('project_id', $projectId)
                ->where('title', $milestoneName)
                ->first();

            if (! $milestone) {
                if ($dryRun) {
                    $this->line("  <fg=cyan>WOULD CREATE MILESTONE</> {$milestoneName}");
                } else {
                    $milestone = DB::transaction(function () use ($projectId, $milestoneName) {
                        $created = Milestone::create([
                            'project_id' => $projectId,
                            'title' => $milestoneName,
                            'status' => 'in_progress',
                            'sort_order' => (int) Milestone::where('project_id', $projectId)->max('sort_order') + 1,
                        ]);

                        $this->logActivity('milestone.created', 'milestone', $created->id, [
                            'title' => $created->title,
                            'via' => 'task:sync',
                        ]);

                        return $created;
                    });
                    $this->line("  Created milestone: {$milestoneName}");
                }
            }

            $milestoneId = $milestone?->id;
        }

        // 4. Create tasks, skipping any whose title already exists on the
        //    project. Titles are compared case-insensitively + trimmed.
        $existing = Task::where('project_id', $projectId)
            ->pluck('title')
            ->map(fn ($t) => strtolower(trim((string) $t)))
            ->all();

        $assigneeId = (int) ($this->readPowerhouseJson('default_assignee_id') ?? 1);

        $created = 0;
        $skipped = 0;
        $taskIds = [];

        foreach ($parsed['tasks'] as $task) {
            $titleKey = strtolower(trim($task['title']));

            if (in_array($titleKey, $existing, true)) {
                $this->line("  <fg=yellow>SKIP</> {$task['title']}");
                $skipped++;

                continue;
            }

            // Remember it so a TASKS.md with duplicate titles doesn't create
            // the same task twice within a single run.
            $existing[] = $titleKey;

            if ($dryRun) {
                $this->line("  <fg=cyan>WOULD CREATE</> {$task['title']}");
                $created++;

                continue;
            }

            $newTask = DB::transaction(function () use ($task, $projectId, $milestoneId, $assigneeId) {
                $t = Task::create([
                    'project_id' => $projectId,
                    'milestone_id' => $milestoneId,
                    'title' => $task['title'],
                    'type' => $task['type'],
                    'priority' => $task['priority'],
                    'status' => 'todo',
                    'estimated_hours' => $task['hours'],
                    'assigned_to' => $assigneeId,
                    'created_by' => 1,
                ]);

                $this->logActivity('task.created', 'task', $t->id, [
                    'title' => $t->title,
                    'status' => 'todo',
                    'via' => 'task:sync',
                ]);

                return $t;
            });

            $taskIds[$task['title']] = $newTask->id;
            $created++;
            $this->line("  <fg=green>CREATED</> [#{$newTask->id}] {$task['title']}");
        }

        // 5. Persist the title → id map so Claude Code can reference real
        //    ids with task:update later in the session.
        if (! empty($taskIds) && ! $dryRun) {
            $dir = base_path('.claude');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(
                $dir.'/sprint-tasks.json',
                json_encode([
                    'project_id' => $projectId,
                    'milestone' => $milestoneName,
                    'synced_at' => now()->toISOString(),
                    'tasks' => $taskIds,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        $this->newLine();
        $this->info("Done. Created: {$created}, Skipped: {$skipped}");
        if ($dryRun) {
            $this->warn('Dry run — no changes made.');
        }

        return self::SUCCESS;
    }

    /**
     * Parse a TASKS.md sprint file into a structured array.
     *
     * @return array{sprint: string, milestone: string|null, tasks: list<array{title: string, type: string, priority: string, hours: float|null, done: bool}>}
     */
    private function parseTasksFile(string $content): array
    {
        /** @var array{sprint: string, milestone: string|null, tasks: list<array{title: string, type: string, priority: string, hours: float|null, done: bool}>} $result */
        $result = ['sprint' => '', 'milestone' => null, 'tasks' => []];

        /** @var array{title: string, type: string, priority: string, hours: float|null, done: bool}|null $currentTask */
        $currentTask = null;
        $sawTask = false;

        foreach (explode("\n", $content) as $line) {
            // Sprint name from the top-level "# Sprint:" heading.
            if (str_starts_with($line, '# Sprint:')) {
                $result['sprint'] = trim(substr($line, 9));

                continue;
            }

            // Milestone from a bare "milestone:" metadata line.
            if (preg_match('/^milestone:\s*(.+)/', $line, $m)) {
                $result['milestone'] = trim($m[1]);

                continue;
            }

            // Once tasks have started, the first following section heading
            // ("## Notes", etc.) or a "---" rule ends the task list — so the
            // template's footer + free-form notes never leak into a task.
            $stripped = ltrim($line);
            if ($sawTask && (str_starts_with($stripped, '#') || str_starts_with($stripped, '---'))) {
                break;
            }

            // Task line: "- [ ] Title" / "- [x] Title".
            if (preg_match('/^-\s*\[\s*[x\s]?\]\s*(.+)/', $line, $m)) {
                if ($currentTask) {
                    $result['tasks'][] = $currentTask;
                }
                $currentTask = [
                    'title' => trim($m[1]),
                    'type' => 'task',
                    'priority' => 'medium',
                    'hours' => null,
                    'done' => str_contains($line, '[x]'),
                ];
                $sawTask = true;

                continue;
            }

            // Metadata line below a task:
            //   "type: task | priority: high | hours: 8"
            if ($currentTask && str_contains($line, ':')) {
                if (preg_match('/type:\s*(\w+)/', $line, $m)) {
                    $currentTask['type'] = $m[1];
                }
                if (preg_match('/priority:\s*(\w+)/', $line, $m)) {
                    $currentTask['priority'] = $m[1];
                }
                if (preg_match('/hours:\s*([\d.]+)/', $line, $m)) {
                    $currentTask['hours'] = (float) $m[1];
                }
            }
        }

        if ($currentTask) {
            $result['tasks'][] = $currentTask;
        }

        return $result;
    }

    /**
     * Record a system-actor audit entry, matching the pattern used by the
     * other artisan commands (null user_id, user_role "system").
     *
     * @param  array<string, mixed>  $after
     */
    private function logActivity(string $action, string $entityType, int $entityId, array $after): void
    {
        ActivityLog::create([
            'user_id' => null,
            'user_role' => 'system',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => null,
            'after' => $after,
        ]);
    }
}
