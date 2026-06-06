<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Services\TicketIntakeService;
use App\Support\TaskDueDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskDueDateRequiredTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function ticket(): SupportTicket
    {
        $customer = Customer::create(['name' => 'Acme '.uniqid()]);

        return SupportTicket::create([
            'customer_id' => $customer->id,
            'subject' => 'Help me',
            'status' => 'open',
            'priority' => 'medium',
            'sla_breach_at' => now()->addHours(24),
        ]);
    }

    public function test_store_rejects_actionable_task_without_due_at(): void
    {
        $this->actingAs($this->staff())
            ->post('/tasks', ['type' => 'task', 'title' => 'Do the thing'])
            ->assertSessionHasErrors('due_at');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_store_accepts_note_and_email_without_due_at(): void
    {
        $staff = $this->staff();

        foreach (['note', 'email'] as $type) {
            $this->actingAs($staff)
                ->post('/tasks', ['type' => $type, 'title' => "A {$type}"])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Task::count());
        $this->assertNull(Task::where('type', 'note')->sole()->due_at);
    }

    public function test_store_accepts_actionable_task_with_due_at(): void
    {
        $this->actingAs($this->staff())
            ->post('/tasks', ['type' => 'task', 'title' => 'Scheduled', 'due_at' => '2026-07-01'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Task::sole()->due_at);
    }

    public function test_store_accepts_timed_meeting_via_start_at_without_due_at(): void
    {
        // A timed event schedules via start_at; the controller mirrors it into
        // due_at, so the requirement must not double-reject it.
        $this->actingAs($this->staff())
            ->post('/tasks', [
                'type' => 'meeting',
                'title' => 'Kickoff call',
                'is_all_day' => false,
                'start_at' => '2026-07-01 10:00:00',
                'end_at' => '2026-07-01 10:30:00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Task::sole()->due_at);
    }

    public function test_customer_store_task_requires_and_persists_due_at(): void
    {
        $customer = Customer::create(['name' => 'Acme']);
        $staff = $this->staff();

        // Missing due → rejected.
        $this->actingAs($staff)
            ->post("/customers/{$customer->id}/tasks", ['title' => 'Follow up'])
            ->assertSessionHasErrors('due_at');
        $this->assertDatabaseCount('tasks', 0);

        // With due → persisted on due_at (not the legacy due_date).
        $this->actingAs($staff)
            ->post("/customers/{$customer->id}/tasks", ['title' => 'Follow up', 'due_at' => '2026-07-01'])
            ->assertSessionHasNoErrors();

        $task = Task::sole();
        $this->assertNotNull($task->due_at);
        $this->assertNull($task->due_date);
    }

    public function test_triage_task_due_at_is_the_sla_deadline_not_now(): void
    {
        $this->staff(); // resolvable triage user

        $ticket = app(TicketIntakeService::class)->create([
            'subject' => 'Help',
            'message' => 'Broken',
            'priority' => 'high', // first_response_hours = 8
        ]);

        $task = Task::where('ticket_id', $ticket->id)->sole();
        $this->assertNotNull($task->due_at);
        // Due ≈ now + 8h (the SLA deadline), never "now"/instant-overdue.
        $this->assertEqualsWithDelta(8, now()->diffInHours($task->due_at, false), 1);
    }

    public function test_support_create_task_requires_due_at_for_actionable_type(): void
    {
        $ticket = $this->ticket();

        // No silent +2d default any more — an actionable type with no due
        // is rejected, exactly like the /tasks endpoint.
        $this->actingAs($this->staff())
            ->post("/helpdesk/{$ticket->id}/task", [
                'title' => 'Call the customer back',
                'type' => 'call',
                'priority' => 'medium',
            ])
            ->assertSessionHasErrors('due_at');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_support_create_task_nulls_a_notes_due_at(): void
    {
        $ticket = $this->ticket();

        // A note is exempt and never carries a schedule, even if a date
        // happens to be posted — resolve() forces it null.
        $this->actingAs($this->staff())
            ->post("/helpdesk/{$ticket->id}/task", [
                'title' => 'Logged a call summary',
                'type' => 'note',
                'priority' => 'medium',
                'due_at' => '2026-07-01',
            ])
            ->assertSessionHasNoErrors();

        $task = Task::where('type', 'note')->sole();
        $this->assertNull($task->due_at);
    }

    public function test_projects_quick_add_succeeds_with_a_due_date(): void
    {
        $staff = $this->staff();
        $project = Project::create(['title' => 'Website rebuild', 'created_by' => $staff->id]);

        // The kanban quick-add posts type=task + project_id + a due date.
        $this->actingAs($staff)
            ->post('/tasks', [
                'type' => 'task',
                'title' => 'Wireframe the homepage',
                'project_id' => $project->id,
                'due_at' => '2026-07-01',
            ])
            ->assertSessionHasNoErrors();

        $task = Task::sole();
        $this->assertSame($project->id, $task->project_id);
        $this->assertNotNull($task->due_at);
    }

    public function test_shared_rule_is_the_single_definition_all_endpoints_reject_identically(): void
    {
        $staff = $this->staff();
        $customer = Customer::create(['name' => 'Acme']);
        $ticket = $this->ticket();

        // The one canonical message — sourced from App\Support\TaskDueDate.
        $message = TaskDueDate::messages()['due_at.required'];
        $this->assertSame('A due date is required for tasks, calls and meetings.', $message);

        // Every user-facing create endpoint rejects a missing due_at for an
        // actionable type with the IDENTICAL message → proves they share the
        // single rule definition rather than each carrying their own copy.
        $this->actingAs($staff)
            ->post('/tasks', ['type' => 'call', 'title' => 'Ring back'])
            ->assertSessionHasErrors(['due_at' => $message]);

        $this->actingAs($staff)
            ->post("/helpdesk/{$ticket->id}/task", ['type' => 'call', 'title' => 'Ring back', 'priority' => 'medium'])
            ->assertSessionHasErrors(['due_at' => $message]);

        $this->actingAs($staff)
            ->post("/customers/{$customer->id}/tasks", ['title' => 'Follow up'])
            ->assertSessionHasErrors(['due_at' => $message]);
    }
}
