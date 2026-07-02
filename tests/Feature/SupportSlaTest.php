<?php

namespace Tests\Feature;

use App\Enums\SlaState;
use App\Models\Company;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportSlaTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function ticket(array $overrides = []): SupportTicket
    {
        $customer = Company::create(['name' => 'Acme '.uniqid()]);

        return SupportTicket::create(array_merge([
            'customer_id' => $customer->id,
            'subject' => 'Help me',
            'status' => 'open',
            'priority' => 'medium',
            'sla_breach_at' => now()->addHours(24),
        ], $overrides));
    }

    public function test_first_staff_reply_stamps_first_responded_at_once(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff())
            ->post("/helpdesk/{$ticket->id}/reply", ['message' => 'On it.'])
            ->assertRedirect();

        $first = $ticket->fresh()->first_responded_at;
        $this->assertNotNull($first);

        // A second reply must NOT move the first-response timestamp.
        $this->travel(2)->hours();
        $this->actingAs($this->staff())
            ->post("/helpdesk/{$ticket->id}/reply", ['message' => 'Following up.'])
            ->assertRedirect();

        $this->assertEquals(
            $first->timestamp,
            $ticket->fresh()->first_responded_at->timestamp,
            'second reply moved first_responded_at',
        );
    }

    public function test_internal_note_does_not_stamp_first_response(): void
    {
        $ticket = $this->ticket();

        // An internal staff note exists on the ticket — but only reply()
        // (staff, non-internal) stamps first response, so it stays null.
        SupportMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'staff',
            'sender_id' => $this->staff()->id,
            'body' => 'Internal: investigating.',
            'is_internal_note' => true,
        ]);

        $this->assertNull($ticket->fresh()->first_responded_at);

        // A non-internal staff reply DOES stamp it.
        $this->actingAs($this->staff())
            ->post("/helpdesk/{$ticket->id}/reply", ['message' => 'Hi, looking into it.'])
            ->assertRedirect();

        $this->assertNotNull($ticket->fresh()->first_responded_at);
    }

    public function test_reopen_stamps_and_increments_on_resolved_to_open(): void
    {
        $ticket = $this->ticket(['status' => 'resolved', 'resolved_at' => now()]);
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post("/helpdesk/{$ticket->id}/status", ['status' => 'open'])
            ->assertRedirect();

        $fresh = $ticket->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertNotNull($fresh->reopened_at);
        $this->assertSame(1, $fresh->reopen_count);
    }

    public function test_status_change_among_open_states_does_not_count_as_reopen(): void
    {
        $ticket = $this->ticket(['status' => 'awaiting_customer']);

        $this->actingAs($this->staff())
            ->post("/helpdesk/{$ticket->id}/status", ['status' => 'open'])
            ->assertRedirect();

        // awaiting_customer → open is NOT a reopen (was never resolved/closed).
        $this->assertSame(0, $ticket->fresh()->reopen_count);
        $this->assertNull($ticket->fresh()->reopened_at);
    }

    public function test_derived_sla_state_across_cases(): void
    {
        // Met: responded before deadline.
        $met = $this->ticket(['sla_breach_at' => now()->addHours(24), 'first_responded_at' => now()->subHour()]);
        $this->assertSame(SlaState::Met, $met->slaState());

        // Breached (late): responded after deadline.
        $late = $this->ticket(['sla_breach_at' => now()->subHours(2), 'first_responded_at' => now()->subHour()]);
        $this->assertSame(SlaState::Breached, $late->slaState());

        // Due: unresponded, deadline still ahead.
        $due = $this->ticket(['sla_breach_at' => now()->addHours(3)]);
        $this->assertSame(SlaState::Due, $due->slaState());
        $this->assertNotNull($due->slaRemainingSeconds());

        // Breached: unresponded, deadline passed.
        $overdue = $this->ticket(['sla_breach_at' => now()->subHour()]);
        $this->assertSame(SlaState::Breached, $overdue->slaState());
        $this->assertNull($overdue->slaRemainingSeconds());
    }

    public function test_create_task_from_ticket_succeeds(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff())
            ->post("/helpdesk/{$ticket->id}/task", [
                'title' => 'Investigate the bug',
                'type' => 'task',
                'priority' => 'medium',
                // due_at is now mandatory for an actionable type (Part C).
                'due_at' => '2026-07-01',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Task persisted with a VALID enum status (not the invalid 'open').
        $task = Task::where('customer_id', $ticket->customer_id)->where('title', 'Investigate the bug')->sole();
        $this->assertSame('todo', $task->status);
    }

    public function test_resolved_without_response_settles_at_resolution_not_due(): void
    {
        // Resolved before the deadline, never responded → MET (not Due),
        // even though the deadline is still in the future.
        $metTicket = $this->ticket([
            'status' => 'resolved',
            'sla_breach_at' => now()->addHours(10),
            'resolved_at' => now()->subHour(),
        ]);
        $this->assertSame(SlaState::Met, $metTicket->slaState());
        $this->assertNull($metTicket->slaRemainingSeconds(), 'terminal ticket must not count down');

        // Resolved after the deadline, never responded → BREACHED.
        $breachedTicket = $this->ticket([
            'status' => 'closed',
            'sla_breach_at' => now()->subHours(5),
            'closed_at' => now()->subHour(),
        ]);
        $this->assertSame(SlaState::Breached, $breachedTicket->slaState());
    }

    public function test_analytics_excludes_unresponded_from_frt_average(): void
    {
        // Responded ticket: FRT = 3h (created 10h ago, responded 7h ago).
        $this->ticket([
            'sla_breach_at' => now()->addHours(14),
            'first_responded_at' => now()->subHours(7),
        ])->forceFill(['created_at' => now()->subHours(10)])->save();

        // Resolved WITHOUT a response, before deadline → settled MET, but
        // must NOT be folded into the first-response average.
        $this->ticket([
            'status' => 'resolved',
            'sla_breach_at' => now()->addHours(14),
            'resolved_at' => now()->subHours(5),
        ])->forceFill(['created_at' => now()->subHours(10)])->save();

        $this->actingAs($this->staff())
            ->get('/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('support.total', 2)
                ->where('support.met', 2)        // both settled as met
                ->where('support.breached', 0)
                // FRT averages ONLY the responded ticket (≈3h), not the unresponded one.
                ->where('support.avg_first_response_hours', fn ($v) => abs((float) $v - 3.0) < 0.2)
            );
    }

    public function test_analytics_support_section_aggregates(): void
    {
        // met: responded in time
        $this->ticket([
            'sla_breach_at' => now()->subHours(20),
            'first_responded_at' => now()->subHours(23),
            'resolved_at' => now()->subHours(18),
        ])->forceFill(['created_at' => now()->subHours(24)])->save();

        // breached: responded late + reopened
        $this->ticket([
            'sla_breach_at' => now()->subHours(20),
            'first_responded_at' => now()->subHours(2),
            'reopen_count' => 2,
        ])->forceFill(['created_at' => now()->subHours(24)])->save();

        // due: unresponded, in window (excluded from decided)
        $this->ticket(['sla_breach_at' => now()->addHours(5)]);

        // breached: unresponded, overdue
        $this->ticket(['sla_breach_at' => now()->subHours(1)]);

        $this->actingAs($this->staff())
            ->get('/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('support.total', 4)
                ->where('support.met', 1)
                ->where('support.breached', 2)
                // Whole-number floats can serialize back as ints, so compare numerically.
                ->where('support.pct_within_sla', fn ($v) => abs((float) $v - 33.3) < 0.05)
                ->where('support.breach_rate', fn ($v) => abs((float) $v - 66.7) < 0.05)
                ->where('support.reopen_rate', fn ($v) => abs((float) $v - 25.0) < 0.05)
            );
    }
}
