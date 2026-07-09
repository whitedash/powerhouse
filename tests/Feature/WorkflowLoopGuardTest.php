<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * In-memory loop guard for WorkflowEngine. Guard semantics: a given workflow
 * fires at most once per top-level triggering event (workflow-id-set), with a
 * hard depth cap as a runaway backstop.
 *
 * No production action re-enters trigger() today, so a genuine same-instance
 * re-entry (the only thing the guard protects against) is driven here by a
 * test-double engine that overrides the protected executeAction() to call
 * $this->trigger() again — see ReentrantTestEngine below. app() can't be used
 * for this: the engine is not a singleton, so app() would hand back a fresh
 * instance with an empty guard, defeating the test.
 */
class WorkflowLoopGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * @param  list<array{action_type: string, config: array<string, mixed>}>  $actions
     */
    private function makeWorkflow(int $creatorId, array $actions, string $trigger = 'form_submitted', string $name = 'WF'): Workflow
    {
        $workflow = Workflow::create([
            'name' => $name,
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

    private function readDepth(WorkflowEngine $engine): int
    {
        $prop = (new \ReflectionClass(WorkflowEngine::class))->getProperty('triggerDepth');
        $prop->setAccessible(true);

        return (int) $prop->getValue($engine);
    }

    public function test_a_re_entering_action_cannot_refire_the_same_workflow_within_one_event(): void
    {
        Log::spy();
        $admin = $this->admin();

        // W creates a lead, then a re-entering action fires the SAME trigger.
        // Without the guard this recurses (create lead → re-enter → create
        // lead → …); the guard must let W fire exactly once.
        $w = $this->makeWorkflow($admin->id, [
            ['action_type' => 'create_lead', 'config' => ['first_name_field' => 'first_name', 'email_field' => 'email']],
            ['action_type' => 'send_notification', 'config' => ['__reenter' => true]],
        ]);

        $engine = new ReentrantTestEngine();
        $engine->reentryTrigger = 'form_submitted';
        $engine->trigger('form_submitted', [
            'first_name' => 'Dana',
            'email' => 'dana@example.com',
            'form_id' => 1,
        ]);

        // The re-entering action ran once (outer run). The nested trigger it
        // fired found W already in the event's id-set and skipped it, so W's
        // actions never ran a second time.
        $this->assertSame(1, $engine->reentries, 'the re-entering action fired exactly once');
        $this->assertSame(1, Lead::where('email', 'dana@example.com')->count(), 'the lead was created once, not per re-entry');
        $this->assertSame(1, (int) $w->fresh()->run_count, 'the workflow recorded exactly one run');

        Log::shouldHaveReceived('warning')
            ->with('Workflow skipped: already fired in this triggering event', \Mockery::on(
                fn (array $ctx): bool => ($ctx['workflow_id'] ?? null) === $w->id
            ))
            ->once();
    }

    public function test_two_workflows_that_re_enter_each_other_do_not_cascade(): void
    {
        $admin = $this->admin();

        // A re-enters the trigger; B creates a lead. Ordered A then B. Outer
        // run: A fires → re-enters → nested run skips A (already fired), runs
        // B (creates the lead) → back in the outer loop B is now already fired
        // and is skipped. Net: A once, B once, one lead — no A→B→A cascade.
        $a = $this->makeWorkflow($admin->id, [
            ['action_type' => 'send_notification', 'config' => ['__reenter' => true]],
        ], name: 'A');
        $b = $this->makeWorkflow($admin->id, [
            ['action_type' => 'create_lead', 'config' => ['first_name_field' => 'first_name', 'email_field' => 'email']],
        ], name: 'B');

        $engine = new ReentrantTestEngine();
        $engine->reentryTrigger = 'form_submitted';
        $engine->trigger('form_submitted', [
            'first_name' => 'Dana',
            'email' => 'dana@example.com',
            'form_id' => 1,
        ]);

        $this->assertSame(1, $engine->reentries, 'A re-entered exactly once');
        $this->assertSame(1, Lead::where('email', 'dana@example.com')->count(), 'B created exactly one lead');
        $this->assertSame(1, (int) $a->fresh()->run_count, 'A ran once');
        $this->assertSame(1, (int) $b->fresh()->run_count, 'B ran once');
    }

    public function test_the_guard_resets_between_sequential_top_level_events(): void
    {
        $admin = $this->admin();

        // The guard is per triggering event, NOT global: a workflow that fired
        // in one event must fire again in the next. Two plain, sequential
        // trigger() calls => two runs.
        $w = $this->makeWorkflow($admin->id, [
            ['action_type' => 'create_lead', 'config' => ['first_name_field' => 'first_name', 'email_field' => 'email']],
        ]);

        $engine = new WorkflowEngine();
        $engine->trigger('form_submitted', ['first_name' => 'One', 'email' => 'one@example.com', 'form_id' => 1]);
        $engine->trigger('form_submitted', ['first_name' => 'Two', 'email' => 'two@example.com', 'form_id' => 1]);

        $this->assertSame(2, (int) $w->fresh()->run_count, 'the same workflow fires on each separate event');
        $this->assertSame(1, Lead::where('email', 'one@example.com')->count());
        $this->assertSame(1, Lead::where('email', 'two@example.com')->count());
        $this->assertSame(0, $this->readDepth($engine), 'depth is back to zero between and after events');
    }

    public function test_trigger_depth_unwinds_to_zero_even_when_a_workflow_action_throws(): void
    {
        Log::spy();
        $admin = $this->admin();

        // A throwing action is isolated (rolled back + reported) and does NOT
        // propagate out of trigger(), but the depth must still unwind via the
        // try/finally so the next event starts clean.
        $this->makeWorkflow($admin->id, [
            ['action_type' => 'send_notification', 'config' => ['__throw' => true]],
        ]);

        $engine = new ReentrantTestEngine();
        $engine->trigger('form_submitted', ['form_id' => 1]);

        $this->assertSame(0, $this->readDepth($engine), 'depth unwinds to zero after a swallowed workflow error');

        // Isolation still held: the failure was reported, the request survived.
        Log::shouldHaveReceived('error')
            ->with('Workflow failed', \Mockery::type('array'))
            ->once();
    }

    public function test_the_depth_cap_aborts_a_runaway_chain_of_distinct_workflows(): void
    {
        Log::spy();
        $admin = $this->admin();

        // The id-set stops the realistic same-workflow loop; the depth cap is
        // the backstop for a chain of DISTINCT workflows the id-set can't catch.
        // Construct exactly that: each level creates a brand-new workflow and
        // fires it, so no id repeats and only the cap can end the descent.
        $seed = $this->makeWorkflow($admin->id, [
            ['action_type' => 'send_notification', 'config' => ['__deepen' => true]],
        ], trigger: 'manual', name: 'deep-0');

        $engine = new ReentrantTestEngine();
        $engine->deepenCreatorId = $admin->id;
        $engine->trigger('manual', []);

        $max = (int) (new \ReflectionClass(WorkflowEngine::class))->getConstant('MAX_TRIGGER_DEPTH');

        // The descent terminated at the cap rather than recursing unbounded.
        Log::shouldHaveReceived('error')
            ->with('Workflow trigger depth cap exceeded', \Mockery::type('array'))
            ->once();

        // Linear descent: the seed plus one workflow created at each level up
        // to (but not past) the cap. That it completed at all proves the cap
        // stopped the recursion before a stack overflow.
        $this->assertSame($max + 1, Workflow::where('trigger_type', 'manual')->count(),
            'the chain stopped at the depth cap');
        $this->assertSame(0, $this->readDepth($engine), 'depth fully unwound after the aborted chain');
    }
}

/**
 * Test double that turns config markers into engine re-entry — the only way to
 * drive a genuine same-instance trigger() re-entry, since no production action
 * does. Markers:
 *   __reenter  => call $this->trigger($this->reentryTrigger) again
 *   __throw    => throw, to exercise the isolation + finally-unwind path
 *   __deepen   => create a fresh DISTINCT workflow and fire it (exercises the
 *                 depth cap, which the id-set can't reach)
 * Any unmarked action defers to the real handler.
 */
class ReentrantTestEngine extends WorkflowEngine
{
    public int $reentries = 0;

    public string $reentryTrigger = 'form_submitted';

    public ?int $deepenCreatorId = null;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function executeAction(WorkflowAction $action, array $context): array
    {
        $config = $action->config;

        if (($config['__throw'] ?? false) === true) {
            throw new \RuntimeException('synthetic action failure');
        }

        if (($config['__reenter'] ?? false) === true) {
            $this->reentries++;
            $this->trigger($this->reentryTrigger, $context);

            return $context;
        }

        if (($config['__deepen'] ?? false) === true) {
            $next = Workflow::create([
                'name' => 'deep-'.$this->reentries,
                'is_active' => true,
                'trigger_type' => 'manual',
                'trigger_config' => null,
                'run_count' => 0,
                'created_by' => $this->deepenCreatorId,
            ]);
            $next->actions()->create([
                'action_type' => 'send_notification',
                'config' => ['__deepen' => true],
                'sort_order' => 0,
            ]);
            $this->reentries++;
            $this->trigger('manual', $context);

            return $context;
        }

        return parent::executeAction($action, $context);
    }
}
