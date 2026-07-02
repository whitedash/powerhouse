<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Projects can be assigned to a customer/prospect — across ALL pipeline stages
 * (leads/prospects selectable, not just active). customer_id already existed;
 * this covers create + edit wiring and the selector's customer list.
 */
class ProjectCustomerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Website rebuild',
            'description' => null,
            'status' => 'planning',
            'priority' => 'medium',
            'colour' => '#3B82F6',
            'due_date' => null,
        ], $overrides);
    }

    public function test_project_is_created_with_an_assigned_customer(): void
    {
        $lead = Company::create(['name' => 'Lead Co', 'pipeline_stage' => 'lead']);

        $this->actingAs($this->staff())
            ->post('/projects', $this->payload(['customer_id' => $lead->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'title' => 'Website rebuild',
            'customer_id' => $lead->id,
        ]);
    }

    public function test_customer_is_selectable_across_pipeline_stages(): void
    {
        // A customer in each stage — none archived.
        foreach (['lead', 'prospect', 'active', 'churned'] as $stage) {
            Company::create(['name' => ucfirst($stage).' Company', 'pipeline_stage' => $stage]);
        }
        $archived = Company::create(['name' => 'Archived Co', 'pipeline_stage' => 'active', 'archived_at' => now()]);

        // The create form (Projects index) lists every non-archived customer
        // regardless of stage; archived ones are excluded.
        $this->actingAs($this->staff())
            ->get('/projects')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customers', fn ($customers) => collect($customers)->pluck('name')->contains('Lead Company')
                    && collect($customers)->pluck('name')->contains('Prospect Company')
                    && collect($customers)->pluck('name')->contains('Churned Company')
                    && ! collect($customers)->pluck('id')->contains($archived->id))
            );
    }

    public function test_edit_form_lists_customers_and_can_reassign(): void
    {
        $user = $this->staff();
        $prospect = Company::create(['name' => 'Prospect Co', 'pipeline_stage' => 'prospect']);
        $newCustomer = Company::create(['name' => 'New Co', 'pipeline_stage' => 'lead']);
        $project = Project::create([
            'title' => 'Brand work', 'status' => 'active', 'priority' => 'medium',
            'colour' => '#3B82F6', 'customer_id' => $prospect->id, 'created_by' => $user->id,
        ]);

        // show() exposes the customers list for the edit selector.
        $this->actingAs($user)
            ->get("/projects/{$project->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('customers')
                ->where('customers', fn ($customers) => collect($customers)->pluck('id')->contains($newCustomer->id))
            );

        // Reassign on edit.
        $this->actingAs($user)
            ->put("/projects/{$project->id}", $this->payload([
                'title' => 'Brand work',
                'status' => 'active',
                'customer_id' => $newCustomer->id,
            ]))
            ->assertRedirect();

        $this->assertSame($newCustomer->id, $project->fresh()->customer_id);
    }

    public function test_project_can_be_unassigned(): void
    {
        $user = $this->staff();
        $customer = Company::create(['name' => 'Acme', 'pipeline_stage' => 'active']);
        $project = Project::create([
            'title' => 'P', 'status' => 'active', 'priority' => 'low',
            'colour' => '#3B82F6', 'customer_id' => $customer->id, 'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->put("/projects/{$project->id}", $this->payload([
                'title' => 'P', 'status' => 'active', 'priority' => 'low',
                'customer_id' => null,
            ]))
            ->assertRedirect();

        $this->assertNull($project->fresh()->customer_id);
    }
}
