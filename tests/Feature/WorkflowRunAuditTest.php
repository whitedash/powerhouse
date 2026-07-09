<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use App\Models\WorkflowRun;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * workflow_runs audit ledger. One row per workflow firing, written OUTSIDE the
 * per-workflow transaction so a failed run (an action throws, its transaction
 * rolls back) STILL leaves a status=failed record — the gap the old
 * in-transaction workflow.executed activity_log row could not fill.
 */
class WorkflowRunAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * @param  list<array{action_type: string, config: array<string, mixed>}>  $actions
     */
    private function makeWorkflow(int $creatorId, array $actions, string $trigger = 'form_submitted'): Workflow
    {
        $workflow = Workflow::create([
            'name' => 'Audit WF',
            'is_active' => true,
            'trigger_type' => $trigger,
            'trigger_config' => null,
            'run_count' => 0,
            'created_by' => $creatorId,
        ]);
        foreach ($actions as $i => $a) {
            $workflow->actions()->create([
                'action_type' => $a['action_type'],
                'config' => $a['config'],
                'sort_order' => $i,
            ]);
        }

        return $workflow;
    }

    public function test_a_succeeded_run_writes_a_complete_row_with_action_outcomes(): void
    {
        $admin = $this->admin();
        $w = $this->makeWorkflow($admin->id, [
            ['action_type' => 'create_lead', 'config' => ['first_name_field' => 'first_name', 'email_field' => 'email']],
            ['action_type' => 'send_notification', 'config' => ['message_template' => 'New lead {{first_name}}']],
        ]);

        (new WorkflowEngine())->trigger('form_submitted', [
            'first_name' => 'Dana',
            'email' => 'dana@example.com',
            'form_id' => 7,
        ], triggerEntityId: 42);

        $lead = Lead::where('email', 'dana@example.com')->firstOrFail();

        $run = WorkflowRun::where('workflow_id', $w->id)->sole();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('form_submitted', $run->trigger_type);
        $this->assertSame(42, $run->trigger_entity_id);
        $this->assertNotNull($run->duration_ms);
        $this->assertGreaterThanOrEqual(0, $run->duration_ms);
        $this->assertSame($lead->id, $run->context_summary['lead_id'] ?? null);

        // Both actions recorded, in order, as ran.
        $this->assertCount(2, $run->actions);
        $this->assertSame('create_lead', $run->actions[0]['action_type']);
        $this->assertSame('ran', $run->actions[0]['outcome']);
        $this->assertSame(0, $run->actions[0]['sort_order']);
        $this->assertNull($run->actions[0]['skip_reason']);
        $this->assertNull($run->actions[0]['error']);
        $this->assertArrayHasKey('duration_ms', $run->actions[0]);
        $this->assertSame('send_notification', $run->actions[1]['action_type']);
        $this->assertSame('ran', $run->actions[1]['outcome']);
    }

    public function test_a_failed_run_still_writes_a_row_with_status_failed_and_the_error(): void
    {
        // THE core fix: an action throws, the per-workflow transaction rolls
        // back (the lead is gone), yet the run must still leave a durable
        // status=failed row with the error — because the ledger write happens
        // OUTSIDE that transaction. This is the gap the old workflow.executed
        // row (written inside the transaction) could not fill.
        $admin = $this->admin();
        $w = $this->makeWorkflow($admin->id, [
            ['action_type' => 'create_lead', 'config' => ['first_name_field' => 'first_name', 'email_field' => 'email']],
            ['action_type' => 'send_notification', 'config' => ['__throw' => true]],
        ]);

        (new AuditTestEngine())->trigger('form_submitted', [
            'first_name' => 'Dana',
            'email' => 'dana@example.com',
            'form_id' => 7,
        ]);

        // The run rolled back — no lead, run_count untouched.
        $this->assertSame(0, Lead::where('email', 'dana@example.com')->count(), 'the run rolled back');
        $this->assertSame(0, (int) $w->fresh()->run_count, 'a failed run does not bump run_count');

        // …but the audit row SURVIVED the rollback.
        $run = WorkflowRun::where('workflow_id', $w->id)->sole();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('kaboom in action', (string) $run->error);
        $this->assertNotNull($run->duration_ms);

        // Per-action outcomes captured up to and including the failure: the
        // first action ran (rolled back with the run), the second failed.
        $this->assertCount(2, $run->actions);
        $this->assertSame('ran', $run->actions[0]['outcome']);
        $this->assertSame('send_notification', $run->actions[1]['action_type']);
        $this->assertSame('failed', $run->actions[1]['outcome']);
        $this->assertStringContainsString('kaboom in action', (string) $run->actions[1]['error']);
    }

    public function test_a_skipped_action_within_a_successful_run_is_recorded(): void
    {
        // add_note needs a customer_id it never gets from a form context, so it
        // silently no-ops today. It must now surface as outcome=skipped with a
        // reason, while the run itself still succeeds.
        $admin = $this->admin();
        $w = $this->makeWorkflow($admin->id, [
            ['action_type' => 'create_lead', 'config' => ['first_name_field' => 'first_name']],
            ['action_type' => 'add_note', 'config' => ['content_template' => 'Hi {{first_name}}']],
        ]);

        (new WorkflowEngine())->trigger('form_submitted', [
            'first_name' => 'Dana',
            'form_id' => 7,
        ]);

        $run = WorkflowRun::where('workflow_id', $w->id)->sole();
        $this->assertSame('succeeded', $run->status, 'a skipped action does not fail the run');
        $this->assertSame('ran', $run->actions[0]['outcome']);
        $this->assertSame('add_note', $run->actions[1]['action_type']);
        $this->assertSame('skipped', $run->actions[1]['outcome']);
        $this->assertSame('no_customer', $run->actions[1]['skip_reason']);
    }

    public function test_the_loop_guard_skip_is_recorded_as_a_skipped_run_row(): void
    {
        // A re-entering action re-fires the same workflow; the loop guard skips
        // the second fire. That decision must be a status=skipped run row, not
        // just a silent continue.
        $admin = $this->admin();
        $w = $this->makeWorkflow($admin->id, [
            ['action_type' => 'send_notification', 'config' => ['__reenter' => true]],
        ]);

        $engine = new AuditTestEngine();
        $engine->trigger('form_submitted', ['form_id' => 7]);

        // Two rows for W: the outer succeeded run, and the guard's skipped run.
        $this->assertSame(1, WorkflowRun::where('workflow_id', $w->id)->where('status', 'succeeded')->count());

        $skip = WorkflowRun::where('workflow_id', $w->id)->where('status', 'skipped')->sole();
        $this->assertStringContainsString('Loop guard', (string) $skip->error);
        $this->assertNull($skip->actions, 'a workflow-level skip records no per-action outcomes');
        $this->assertNull($skip->duration_ms);
    }
}

/**
 * Test double injecting engine behaviour no production action exhibits:
 *   __throw    => throw from the action (to exercise the failed-run ledger path)
 *   __reenter  => re-enter trigger() on the same instance (to exercise the
 *                 loop guard's skipped run row)
 * Any unmarked action defers to the real handler. executeAction is protected
 * on the engine precisely for this.
 */
class AuditTestEngine extends WorkflowEngine
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function executeAction(WorkflowAction $action, array $context): array
    {
        if (($action->config['__throw'] ?? false) === true) {
            throw new \RuntimeException('kaboom in action');
        }

        if (($action->config['__reenter'] ?? false) === true) {
            $this->trigger('form_submitted', $context);

            return $context;
        }

        return parent::executeAction($action, $context);
    }
}
