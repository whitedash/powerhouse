<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The engine evaluates a workflow's conditions gate after the trigger_config match
 * and before the action transaction. A gated-out workflow never enters the
 * transaction, so its run_count stays 0; a matching (or null/empty) gate fires and
 * bumps run_count to 1.
 */
class WorkflowConditionsGateTest extends TestCase
{
    use RefreshDatabase;

    private function workflow(?array $conditions): Workflow
    {
        return Workflow::create([
            'name' => 'Gated WF',
            'is_active' => true,
            'trigger_type' => 'form_submitted',
            'trigger_config' => null,   // matches any form_submitted
            'conditions' => $conditions,
            'run_count' => 0,
            'created_by' => User::factory()->create(['role' => 'super_admin'])->id,
        ]);
    }

    /** Fire form_submitted and return the workflow's run_count (1 = fired, 0 = gated out). */
    private function fire(Workflow $wf, array $payload): int
    {
        app(WorkflowEngine::class)->trigger('form_submitted', array_merge($payload, ['form_id' => 1]));

        return (int) $wf->fresh()->run_count;
    }

    public function test_null_conditions_still_fires(): void
    {
        $this->assertSame(1, $this->fire($this->workflow(null), ['budget' => '5000']));
    }

    public function test_empty_groups_still_fires(): void
    {
        $this->assertSame(1, $this->fire($this->workflow(['logic' => 'or', 'groups' => []]), ['budget' => '5000']));
    }

    public function test_matching_conditions_fire(): void
    {
        $conditions = ['logic' => 'or', 'groups' => [['logic' => 'and', 'conditions' => [
            ['field_key' => 'budget', 'operator' => 'greater_than', 'value' => '10000'],
        ]]]];

        $this->assertSame(1, $this->fire($this->workflow($conditions), ['budget' => '15000']));
    }

    public function test_non_matching_conditions_gate_the_workflow_out(): void
    {
        $conditions = ['logic' => 'or', 'groups' => [['logic' => 'and', 'conditions' => [
            ['field_key' => 'budget', 'operator' => 'greater_than', 'value' => '10000'],
        ]]]];

        $this->assertSame(0, $this->fire($this->workflow($conditions), ['budget' => '5000']));
    }

    public function test_cross_group_or_lets_a_second_group_fire(): void
    {
        $conditions = ['logic' => 'or', 'groups' => [
            ['logic' => 'and', 'conditions' => [['field_key' => 'budget', 'operator' => 'greater_than', 'value' => '10000']]],
            ['logic' => 'and', 'conditions' => [['field_key' => 'priority', 'operator' => 'equals', 'value' => 'urgent']]],
        ]];

        // Budget fails but the priority group matches → fires.
        $this->assertSame(1, $this->fire($this->workflow($conditions), ['budget' => '500', 'priority' => 'urgent']));
    }
}
