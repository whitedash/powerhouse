<?php

namespace Tests\Feature;

use App\Mail\SupportTicketReply;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Services\TicketIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Support v2 — slice 1 (guest reply loop) + slice 2 (triage task linked
 * to its ticket via tasks.ticket_id).
 */
class SupportV2Test extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function guestTicket(): SupportTicket
    {
        return SupportTicket::create([
            'customer_id' => null,
            'guest_name' => 'Pat Guest',
            'guest_email' => 'pat@gmail.com',
            'subject' => 'Cannot log in',
            'status' => 'open',
            'priority' => 'medium',
            'sla_breach_at' => now()->addHours(24),
        ]);
    }

    /** SLICE 1: a staff reply on a GUEST ticket emails guest_email, threaded. */
    public function test_staff_reply_on_guest_ticket_emails_guest_with_ticket_reply_to(): void
    {
        Mail::fake();
        config(['services.postmark.support_inbound_email' => 'support@whitedash.com']);

        $ticket = $this->guestTicket();

        $this->actingAs($this->staff())
            ->post(route('internal.support.reply', $ticket->id), [
                'message' => 'Try the reset link we just sent.',
            ])
            ->assertRedirect();

        // The reply was persisted and the ticket moved to awaiting_customer.
        $this->assertDatabaseHas('support_messages', [
            'ticket_id' => $ticket->id,
            'sender_type' => 'staff',
            'is_internal_note' => false,
        ]);
        $this->assertSame('awaiting_customer', $ticket->fresh()->status);

        Mail::assertSent(SupportTicketReply::class, function (SupportTicketReply $mail) use ($ticket) {
            // build() populates the Reply-To set inside the mailable's build().
            $mail->build();

            return $mail->hasTo('pat@gmail.com')
                && $mail->hasReplyTo('ticket+'.$ticket->id.'@whitedash.com');
        });
    }

    /** SLICE 2: ticket creation sets the triage task's ticket_id and ticket() resolves. */
    public function test_ticket_creation_links_triage_task_via_ticket_id(): void
    {
        Mail::fake();
        $this->staff(); // resolvable triage user for the Task's NOT-NULL FKs

        $ticket = app(TicketIntakeService::class)->create([
            'guest_name' => 'Pat Guest',
            'guest_email' => 'pat@gmail.com',
            'subject' => 'Cannot log in',
            'message' => 'I forgot which email I used.',
        ]);

        $task = Task::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($task, 'Triage task should carry the ticket_id link.');
        $this->assertSame($ticket->id, $task->ticket_id);

        // The belongsTo relation resolves back to the ticket.
        $this->assertInstanceOf(SupportTicket::class, $task->ticket);
        $this->assertSame($ticket->id, $task->ticket->id);
    }

    /** SLICE 2 (optional): resolving a ticket completes its linked triage task. */
    public function test_resolving_ticket_completes_linked_triage_task(): void
    {
        Mail::fake();
        $this->staff();

        $ticket = app(TicketIntakeService::class)->create([
            'guest_name' => 'Pat Guest',
            'guest_email' => 'pat@gmail.com',
            'subject' => 'Cannot log in',
            'message' => 'Help.',
        ]);

        $task = Task::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('todo', $task->status);

        $this->actingAs($this->staff())
            ->post(route('internal.support.status', $ticket->id), [
                'status' => 'resolved',
            ])
            ->assertRedirect();

        $task->refresh();
        $this->assertSame('complete', $task->status);
        $this->assertNotNull($task->completed_at);
    }
}
