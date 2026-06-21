<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The conditions structure is validated with explicit per-key rules (Approach A):
 * an unknown operator is a clean 422 (the Rule::in allowlist), and a valid
 * structure persists intact and round-trips through the model's array cast.
 */
class WorkflowConditionsValidationTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function validConditions(string $operator = 'greater_than'): array
    {
        return [
            'logic' => 'or',
            'groups' => [
                ['logic' => 'and', 'conditions' => [
                    ['field_key' => 'budget', 'operator' => $operator, 'value' => '10000'],
                ]],
            ],
        ];
    }

    public function test_invalid_operator_is_a_clean_422(): void
    {
        $resp = $this->actingAs($this->superAdmin())->post('/workflows', [
            'name' => 'Gated',
            'trigger_type' => 'form_submitted',
            'is_active' => true,
            'conditions' => $this->validConditions('totally_not_an_operator'),
        ]);

        $resp->assertStatus(302)
            ->assertSessionHasErrors(['conditions.groups.0.conditions.0.operator']);
        $this->assertSame(0, Workflow::count());
    }

    public function test_valid_conditions_persist_and_round_trip_through_the_cast(): void
    {
        $this->actingAs($this->superAdmin())->post('/workflows', [
            'name' => 'Gated',
            'trigger_type' => 'form_submitted',
            'is_active' => true,
            'conditions' => $this->validConditions(),
        ])->assertSessionHasNoErrors();

        $conditions = Workflow::sole()->conditions;

        $this->assertIsArray($conditions);
        $this->assertSame('or', $conditions['logic']);
        $this->assertSame('and', $conditions['groups'][0]['logic']);
        $this->assertSame('budget', $conditions['groups'][0]['conditions'][0]['field_key']);
        $this->assertSame('greater_than', $conditions['groups'][0]['conditions'][0]['operator']);
        $this->assertSame('10000', $conditions['groups'][0]['conditions'][0]['value']);
    }

    public function test_no_conditions_persists_as_null(): void
    {
        $this->actingAs($this->superAdmin())->post('/workflows', [
            'name' => 'Ungated',
            'trigger_type' => 'form_submitted',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNull(Workflow::sole()->conditions);
    }
}
