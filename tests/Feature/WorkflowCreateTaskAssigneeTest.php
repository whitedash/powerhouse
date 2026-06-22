<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * create_task default-assignee fix. tasks.assigned_to and tasks.created_by are
 * NOT NULL FKs; a create_task action saved with no assignee (the builder's
 * "Assigned to" default binds null) used to insert null, throw, and be swallowed
 * by trigger() — a silent no-op. The engine now resolves both FKs through
 * resolveWorkflowActorId (configured-if-it-exists → first super_admin), so the
 * task is always created with a real owner, and a swallowed action failure is
 * now logged with the culprit action's identity.
 */
class WorkflowCreateTaskAssigneeTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $taskConfig */
    private function makeCreateTaskWorkflow(User $creator, array $taskConfig): Workflow
    {
        $workflow = Workflow::create([
            'name' => 'Create task WF',
            'is_active' => true,
            'trigger_type' => 'form_submitted',
            'trigger_config' => null,
            'run_count' => 0,
            'created_by' => $creator->id,
        ]);
        $workflow->actions()->create([
            'action_type' => 'create_task',
            'config' => $taskConfig,
            'sort_order' => 0,
        ]);

        return $workflow;
    }

    public function test_create_task_with_no_assignee_falls_back_to_the_super_admin(): void
    {
        // Single super_admin → the deterministic "earliest (lowest id)" fallback.
        $admin = User::factory()->create(['role' => 'super_admin']);
        $workflow = $this->makeCreateTaskWorkflow($admin, [
            'title_template' => 'Follow up {{first_name}}',
            // No assigned_to, no created_by — the previously-silent no-op case.
        ]);

        app(WorkflowEngine::class)->trigger('form_submitted', ['first_name' => 'Dana']);

        $task = Task::latest('id')->firstOrFail();
        $this->assertSame($admin->id, $task->assigned_to, 'assignee defaults to the super_admin');
        $this->assertSame($admin->id, $task->created_by, 'created_by defaults to the super_admin (no magic 1)');
        $this->assertNotNull($task->due_at, 'the rest of the payload still populates');
        $this->assertSame('Follow up Dana', $task->title);
        // Run committed — not rolled back by a swallowed insert failure.
        $this->assertSame(1, $workflow->fresh()->run_count);
    }

    public function test_create_task_with_a_stale_assignee_falls_back_and_does_not_throw(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $workflow = $this->makeCreateTaskWorkflow($admin, [
            'title_template' => 'Stale',
            'assigned_to' => 999999, // non-existent → existence-checked, falls back
            'created_by' => 999999,
        ]);

        app(WorkflowEngine::class)->trigger('form_submitted', ['first_name' => 'Dana']);

        $task = Task::latest('id')->firstOrFail();
        $this->assertSame($admin->id, $task->assigned_to);
        $this->assertSame($admin->id, $task->created_by);
        $this->assertSame(1, $workflow->fresh()->run_count);
    }

    public function test_a_failing_action_logs_a_warning_with_the_action_identity(): void
    {
        Log::spy();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->makeCreateTaskWorkflow($admin, ['title_template' => 'Boom']);

        // customer_id 999999 does not exist → tasks.customer_id FK violation
        // inside create_task (a failure NOT cured by the assignee fix), so the
        // run is swallowed — and must now be observable.
        app(WorkflowEngine::class)->trigger('form_submitted', ['first_name' => 'Dana', 'customer_id' => 999999]);

        $this->assertDatabaseMissing('tasks', ['title' => 'Boom']);
        Log::shouldHaveReceived('warning')
            ->with('Workflow action failed', \Mockery::on(
                fn (array $ctx): bool => ($ctx['action_type'] ?? null) === 'create_task'
                    && isset($ctx['action_id'])
                    && isset($ctx['error'])
            ))
            ->once();
    }
}
