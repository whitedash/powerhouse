<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A task's CRM subject is optional and singular: it may link to a lead
 * OR a customer, or to neither (a standalone task) — never to both.
 * These tests pin the write path that previously dropped lead_id
 * (unvalidated keys are stripped by $request->validate()).
 */
class TaskSubjectAssociationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function lead(User $creator): Lead
    {
        return Lead::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'company' => 'Acme Ltd',
            'created_by' => $creator->id,
        ]);
    }

    public function test_standalone_task_persists_with_no_subject(): void
    {
        $this->actingAs($this->staff())
            ->post('/tasks', ['type' => 'task', 'title' => 'Solo errand', 'due_at' => '2026-07-01'])
            ->assertSessionHasNoErrors();

        $task = Task::sole();
        $this->assertNull($task->lead_id);
        $this->assertNull($task->customer_id);
    }

    public function test_lead_task_persists_lead_id_and_appears_on_the_lead_page(): void
    {
        $staff = $this->staff();
        $lead = $this->lead($staff);

        $this->actingAs($staff)
            ->post('/tasks', [
                'type' => 'task',
                'title' => 'Follow up with Jane',
                'due_at' => '2026-07-01',
                'lead_id' => $lead->id,
            ])
            ->assertSessionHasNoErrors();

        $task = Task::sole();
        $this->assertSame($lead->id, $task->lead_id);
        $this->assertNull($task->customer_id);

        // …and the read path surfaces it in the lead page props.
        $this->actingAs($staff)
            ->get("/leads/{$lead->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Internal/Leads/Show')
                ->count('lead.tasks', 1)
                ->where('lead.tasks.0.id', $task->id)
                ->where('lead.tasks.0.title', 'Follow up with Jane'));
    }

    public function test_store_rejects_a_task_linked_to_both_a_lead_and_a_customer(): void
    {
        $staff = $this->staff();
        $lead = $this->lead($staff);
        $customer = Customer::create(['name' => 'Acme']);

        $this->actingAs($staff)
            ->post('/tasks', [
                'type' => 'task',
                'title' => 'Greedy task',
                'due_at' => '2026-07-01',
                'lead_id' => $lead->id,
                'customer_id' => $customer->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_update_rejects_a_resulting_dual_subject(): void
    {
        $staff = $this->staff();
        $lead = $this->lead($staff);
        $customer = Customer::create(['name' => 'Acme']);

        $this->actingAs($staff)
            ->post('/tasks', ['type' => 'task', 'title' => 'Customer task', 'due_at' => '2026-07-01', 'customer_id' => $customer->id])
            ->assertSessionHasNoErrors();
        $task = Task::sole();

        // customer_id is absent from the request, so the task keeps its
        // current customer — adding a lead must still be rejected.
        $this->actingAs($staff)
            ->put("/tasks/{$task->id}", [
                'type' => 'task',
                'title' => 'Customer task',
                'due_at' => '2026-07-01',
                'lead_id' => $lead->id,
            ])
            ->assertStatus(422);

        $this->assertSame($customer->id, $task->fresh()->customer_id);
        $this->assertNull($task->fresh()->lead_id);
    }

    public function test_update_can_switch_and_clear_the_subject(): void
    {
        $staff = $this->staff();
        $lead = $this->lead($staff);
        $customer = Customer::create(['name' => 'Acme']);

        $this->actingAs($staff)
            ->post('/tasks', ['type' => 'task', 'title' => 'Movable', 'due_at' => '2026-07-01', 'lead_id' => $lead->id])
            ->assertSessionHasNoErrors();
        $task = Task::sole();

        // Switch lead → customer (explicit null clears the lead side).
        $this->actingAs($staff)
            ->put("/tasks/{$task->id}", [
                'type' => 'task',
                'title' => 'Movable',
                'due_at' => '2026-07-01',
                'lead_id' => null,
                'customer_id' => $customer->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($customer->id, $task->fresh()->customer_id);
        $this->assertNull($task->fresh()->lead_id);

        // Clear both — back to a standalone task.
        $this->actingAs($staff)
            ->put("/tasks/{$task->id}", [
                'type' => 'task',
                'title' => 'Movable',
                'due_at' => '2026-07-01',
                'lead_id' => null,
                'customer_id' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($task->fresh()->customer_id);
        $this->assertNull($task->fresh()->lead_id);
    }

    public function test_link_options_endpoint_searches_leads_and_customers(): void
    {
        $staff = $this->staff();
        $this->lead($staff);
        Customer::create(['name' => 'Beta Corp']);

        $this->actingAs($staff)
            ->getJson('/tasks/link-options?type=lead&q=acme')
            ->assertOk()
            ->assertJsonCount(1, 'options')
            ->assertJsonPath('options.0.label', 'Jane Doe');

        $this->actingAs($staff)
            ->getJson('/tasks/link-options?type=customer&q=beta')
            ->assertOk()
            ->assertJsonCount(1, 'options')
            ->assertJsonPath('options.0.label', 'Beta Corp');
    }
}
