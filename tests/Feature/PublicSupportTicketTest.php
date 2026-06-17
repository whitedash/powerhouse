<?php

namespace Tests\Feature;

use App\Mail\SupportTicketReceived;
use App\Mail\SupportTicketStaffAlert;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicSupportTicketTest extends TestCase
{
    use RefreshDatabase;

    /** A real super_admin so TicketIntakeService can resolve the triage Task's created_by. */
    private function seedTriageUser(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_guest_submission_creates_a_ticket_with_message_task_and_ack_email(): void
    {
        Mail::fake();
        $triage = $this->seedTriageUser();

        $response = $this->post('/support', [
            'guest_name' => 'Pat Guest',
            'guest_email' => 'pat@example.com',
            'guest_phone' => '+44 7700 900000',
            'subject' => 'Cannot log in',
            'message' => 'I forgot which email I used.',
        ]);

        $response->assertRedirect(route('support.submitted'));

        $ticket = SupportTicket::first();
        $this->assertNotNull($ticket);
        $this->assertNull($ticket->customer_id);
        $this->assertSame('pat@example.com', $ticket->guest_email);
        $this->assertSame('Pat Guest', $ticket->guest_name);
        $this->assertSame('open', $ticket->status);

        $this->assertDatabaseHas('support_messages', [
            'ticket_id' => $ticket->id,
            'sender_type' => 'customer',
            'source' => 'web',
        ]);

        // Admin triage task, soft-linked by reference. tasks.assigned_to is
        // NOT NULL, so it lands in the resolved triage user's queue (here the
        // seeded super_admin), with created_by the same real user — never magic 1.
        $this->assertDatabaseHas('tasks', [
            'title' => "New support ticket #{$ticket->id} — Cannot log in",
            'assigned_to' => $triage->id,
            'created_by' => $triage->id,
            'status' => 'todo',
        ]);

        Mail::assertSent(SupportTicketReceived::class, fn ($m) => $m->hasTo('pat@example.com'));

        // Staff are alerted by email too — to the resolved triage user
        // (no support.notify_email set in tests).
        Mail::assertSent(SupportTicketStaffAlert::class, fn ($m) => $m->hasTo($triage->email));
    }

    public function test_guest_submission_invalidates_support_nav_badge_caches(): void
    {
        Mail::fake();
        $this->seedTriageUser();

        // Prime the badge caches as HandleInertiaRequests::share().nav would.
        Cache::put('nav.support_open', 41, 60);
        Cache::put('nav.support_sla_breached', 7, 60);

        $this->post('/support', [
            'guest_name' => 'Pat Guest',
            'guest_email' => 'pat@example.com',
            'subject' => 'Cannot log in',
            'message' => 'I forgot which email I used.',
        ])->assertRedirect(route('support.submitted'));

        // A new open ticket must bump both staff badges immediately, not after
        // the 60s TTL — so the intake path forgets both keys.
        $this->assertFalse(Cache::has('nav.support_open'));
        $this->assertFalse(Cache::has('nav.support_sla_breached'));
    }

    public function test_honeypot_blocks_ticket_creation(): void
    {
        Mail::fake();
        $this->seedTriageUser();

        $this->post('/support', [
            'guest_name' => 'Bot',
            'guest_email' => 'bot@example.com',
            'subject' => 'spam',
            'message' => 'spam body',
            '_hp' => 'i am a bot',
        ]);

        $this->assertDatabaseCount('support_tickets', 0);
        Mail::assertNothingSent();
    }

    public function test_create_ticket_workflow_action_path(): void
    {
        Mail::fake();
        $owner = $this->seedTriageUser();

        $workflow = Workflow::create([
            'name' => 'Contact form → ticket',
            'is_active' => true,
            'trigger_type' => 'form_submitted',
            'created_by' => $owner->id,
        ]);
        WorkflowAction::create([
            'workflow_id' => $workflow->id,
            'action_type' => 'create_ticket',
            'config' => [
                'subject_field' => 'subject',
                'message_field' => 'message',
                'email_field' => 'email',
                'name_field' => 'name',
                'source' => 'workflow',
            ],
            'sort_order' => 0,
        ]);

        app(WorkflowEngine::class)->trigger('form_submitted', [
            'name' => 'Form Guest',
            'email' => 'formguest@example.com',
            'subject' => 'Via embedded form',
            'message' => 'Sent through a contact form.',
        ]);

        $ticket = SupportTicket::where('guest_email', 'formguest@example.com')->first();
        $this->assertNotNull($ticket);
        $this->assertNull($ticket->customer_id);

        $this->assertDatabaseHas('support_messages', [
            'ticket_id' => $ticket->id,
            'source' => 'workflow',
        ]);
        Mail::assertSent(SupportTicketReceived::class);
    }
}
