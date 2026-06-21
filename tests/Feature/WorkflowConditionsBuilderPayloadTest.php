<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowConditionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The builder needs two things from the index payload (which also feeds the
 * edit slide-over via openEdit): the operator allowlist (single-sourced with the
 * validator) and each workflow's stored conditions, round-tripping through the
 * model's array cast so the editor can hydrate them.
 */
class WorkflowConditionsBuilderPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_exposes_operators_and_round_trips_conditions(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $conditions = [
            'logic' => 'or',
            'groups' => [
                ['logic' => 'and', 'conditions' => [
                    ['field_key' => 'budget', 'operator' => 'greater_than', 'value' => '10000'],
                ]],
            ],
        ];

        Workflow::create([
            'name' => 'Gated',
            'is_active' => true,
            'trigger_type' => 'form_submitted',
            'trigger_config' => null,
            'conditions' => $conditions,
            'run_count' => 0,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/workflows')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Internal/Workflows/Index')
                ->where('operators', WorkflowConditionEvaluator::OPERATORS)
                ->where('workflows.0.conditions', $conditions)
            );
    }

    public function test_workflow_without_conditions_exposes_null(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        Workflow::create([
            'name' => 'Ungated',
            'is_active' => true,
            'trigger_type' => 'form_submitted',
            'trigger_config' => null,
            'run_count' => 0,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/workflows')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('workflows.0.conditions', null));
    }
}
