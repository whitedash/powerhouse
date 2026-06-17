<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkflowCreateTicketActionTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_create_ticket_action_saves_through_builder_and_fires_from_form_context(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();

        // Save a create_ticket workflow through the validated controller path
        // (the action was previously unreachable — rejected by ACTION_TYPES).
        $this->actingAs($admin)
            ->post('/workflows', [
                'name' => 'Contact form → ticket',
                'is_active' => true,
                'trigger_type' => 'form_submitted',
                'actions' => [[
                    'action_type' => 'create_ticket',
                    'config' => [
                        'subject_field' => 'subject',
                        'message_field' => 'message',
                        'priority' => 'high',
                        'name_field' => 'name',
                        'email_field' => 'email',
                        'phone_field' => 'phone',
                        'source' => 'workflow',
                    ],
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $workflow = Workflow::with('actions')->sole();
        $this->assertSame('create_ticket', $workflow->actions->first()->action_type);

        // Fire it with form context; the existing engine handler creates the
        // ticket + first message via TicketIntakeService.
        app(WorkflowEngine::class)->trigger('form_submitted', [
            'subject' => 'Cannot log in',
            'message' => 'I forgot which email I used.',
            'name' => 'Pat Guest',
            'email' => 'pat@example.com',
            'phone' => '+44 7700 900000',
        ]);

        $ticket = SupportTicket::where('guest_email', 'pat@example.com')->first();
        $this->assertNotNull($ticket);
        $this->assertSame('Cannot log in', $ticket->subject);
        $this->assertSame('high', $ticket->priority);

        $this->assertDatabaseHas('support_messages', [
            'ticket_id' => $ticket->id,
            'body' => 'I forgot which email I used.',
            'source' => 'workflow',
        ]);
    }

    public function test_create_ticket_requires_message_field_at_save(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/workflows', [
                'name' => 'Broken ticket workflow',
                'trigger_type' => 'form_submitted',
                'actions' => [[
                    'action_type' => 'create_ticket',
                    'config' => ['subject_field' => 'subject'], // no message_field
                ]],
            ])
            ->assertSessionHasErrors('actions.0.config.message_field');

        $this->assertDatabaseCount('workflows', 0);
    }
}
