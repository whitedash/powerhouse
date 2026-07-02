<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lead pipeline automation (hardcoded — TaskObserver + proposal-accept hook):
 *  - call/meeting task COMPLETION and email/note task CREATION move a new lead
 *    to contacted;
 *  - an accepted proposal moves a uniquely-matched unconverted 'proposal' lead
 *    to won, and flags rather than picks on an ambiguous match.
 * Every transition writes a lead.status_changed audit row and only ever fires
 * from the exact expected starting status.
 */
class LeadStatusAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['role' => 'super_admin']);
    }

    private function lead(string $status = 'new', array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'first_name' => 'Prospect', 'status' => $status, 'created_by' => $this->staff->id,
        ], $overrides));
    }

    private function task(array $overrides): Task
    {
        return Task::create(array_merge([
            'title' => 'Activity', 'assigned_to' => $this->staff->id, 'created_by' => $this->staff->id,
            'type' => 'call', 'status' => 'todo',
        ], $overrides));
    }

    private function assertContactedAudit(int $leadId, string $via): void
    {
        $log = ActivityLog::where('action', 'lead.status_changed')
            ->where('entity_type', 'lead')->where('entity_id', $leadId)->firstOrFail();
        $this->assertSame('new', $log->after['from']);
        $this->assertSame('contacted', $log->after['to']);
        $this->assertSame($via, $log->after['via']);
    }

    // ── Task rule ────────────────────────────────────────────────────────

    public function test_completing_a_call_task_moves_a_new_lead_to_contacted(): void
    {
        $lead = $this->lead('new');
        $task = $this->task(['type' => 'call', 'lead_id' => $lead->id, 'status' => 'todo']);

        $task->update(['status' => 'complete', 'completed_at' => now()]);

        $this->assertSame('contacted', $lead->fresh()->status);
        $this->assertContactedAudit($lead->id, 'task_call');
    }

    public function test_completing_a_meeting_task_moves_a_new_lead_to_contacted(): void
    {
        $lead = $this->lead('new');
        $task = $this->task(['type' => 'meeting', 'lead_id' => $lead->id]);

        $task->update(['status' => 'complete', 'completed_at' => now()]);

        $this->assertSame('contacted', $lead->fresh()->status);
    }

    public function test_creating_an_email_task_moves_a_new_lead_to_contacted(): void
    {
        $lead = $this->lead('new');
        $this->task(['type' => 'email', 'lead_id' => $lead->id, 'status' => 'todo']);

        $this->assertSame('contacted', $lead->fresh()->status);
        $this->assertContactedAudit($lead->id, 'task_email');
    }

    public function test_creating_a_note_task_moves_a_new_lead_to_contacted(): void
    {
        $lead = $this->lead('new');
        $this->task(['type' => 'note', 'lead_id' => $lead->id]);

        $this->assertSame('contacted', $lead->fresh()->status);
    }

    public function test_completing_a_task_on_a_non_new_lead_does_nothing(): void
    {
        $lead = $this->lead('qualified');
        $task = $this->task(['type' => 'call', 'lead_id' => $lead->id]);

        $task->update(['status' => 'complete']);

        $this->assertSame('qualified', $lead->fresh()->status, 'a later status is never overwritten');
        $this->assertDatabaseMissing('activity_log', [
            'action' => 'lead.status_changed', 'entity_id' => $lead->id,
        ]);
    }

    public function test_a_task_with_no_lead_id_does_nothing(): void
    {
        $customer = Customer::create(['name' => 'Acme', 'pipeline_stage' => 'active']);
        $task = $this->task(['type' => 'call', 'customer_id' => $customer->id, 'lead_id' => null]);

        // No lead to touch; must not error or log.
        $task->update(['status' => 'complete']);
        $this->assertDatabaseMissing('activity_log', ['action' => 'lead.status_changed']);
    }

    public function test_a_generic_task_type_never_transitions(): void
    {
        // 'task' is the 5th type — neither a create- nor complete-contact type.
        $lead = $this->lead('new');
        $task = $this->task(['type' => 'task', 'lead_id' => $lead->id, 'status' => 'todo']);
        $task->update(['status' => 'complete']);

        $this->assertSame('new', $lead->fresh()->status);
    }

    public function test_a_call_created_already_complete_fires_exactly_once(): void
    {
        $lead = $this->lead('new');
        $this->task(['type' => 'call', 'lead_id' => $lead->id, 'status' => 'complete', 'completed_at' => now()]);

        $this->assertSame('contacted', $lead->fresh()->status);
        // Exactly one audit row — created() handled it, updated() never fired.
        $this->assertSame(1, ActivityLog::where('action', 'lead.status_changed')
            ->where('entity_id', $lead->id)->count());
    }

    // ── Proposal rule ────────────────────────────────────────────────────

    /** @return array{0: Proposal, 1: string} the proposal and its raw accept token */
    private function sentProposal(string $contactEmail): array
    {
        $customer = Customer::create(['name' => 'Acme Co', 'pipeline_stage' => 'active']);
        Contact::create([
            'customer_id' => $customer->id, 'name' => 'Pat', 'email' => $contactEmail,
            'role' => 'owner', 'is_primary' => true,
        ]);
        $raw = hash('sha256', 'tok-'.$contactEmail);
        $proposal = Proposal::create([
            'customer_id' => $customer->id, 'reference' => 'PROP-2026-'.substr(md5($contactEmail), 0, 4),
            'title' => 'Build', 'status' => 'sent',
            'subtotal' => 1000, 'vat_rate' => 20, 'vat_amount' => 200, 'total' => 1200,
            'acceptance_token' => hash('sha256', $raw), // hashed at rest
            'acceptance_token_expires_at' => now()->addDays(10),
            'created_by' => $this->staff->id,
        ]);

        return [$proposal, $raw];
    }

    private function accept(string $raw): void
    {
        $this->post("/proposals/accept/{$raw}", [
            'accepted_name' => 'Pat Buyer', 'accepted_confirm' => true,
        ])->assertOk();
    }

    public function test_acceptance_with_one_matching_proposal_lead_moves_it_to_won(): void
    {
        [$proposal, $raw] = $this->sentProposal('pat@acme.test');
        $lead = $this->lead('proposal', ['email' => 'pat@acme.test']);

        $this->accept($raw);

        $this->assertSame('won', $lead->fresh()->status);
        $log = ActivityLog::where('action', 'lead.status_changed')
            ->where('entity_id', $lead->id)->firstOrFail();
        $this->assertSame('proposal', $log->after['from']);
        $this->assertSame('won', $log->after['to']);
        $this->assertSame('proposal_accepted', $log->after['via']);
        $this->assertSame($proposal->id, $log->after['proposal_id']);
    }

    public function test_acceptance_with_zero_matching_leads_does_nothing_and_does_not_error(): void
    {
        [, $raw] = $this->sentProposal('nobody@acme.test');
        // A proposal-status lead with a DIFFERENT email — must not be touched.
        $other = $this->lead('proposal', ['email' => 'someone.else@acme.test']);

        $this->accept($raw);

        $this->assertSame('proposal', $other->fresh()->status);
        $this->assertDatabaseMissing('activity_log', ['action' => 'lead.status_changed']);
    }

    public function test_acceptance_with_multiple_matching_leads_flags_ambiguity(): void
    {
        [$proposal, $raw] = $this->sentProposal('dup@acme.test');
        // leads.email is not unique — two unconverted leads share the email.
        $a = $this->lead('proposal', ['email' => 'dup@acme.test']);
        $b = $this->lead('proposal', ['email' => 'dup@acme.test']);

        $this->accept($raw);

        // Neither is auto-picked.
        $this->assertSame('proposal', $a->fresh()->status);
        $this->assertSame('proposal', $b->fresh()->status);
        $this->assertDatabaseMissing('activity_log', ['action' => 'lead.status_changed']);
        // Ambiguity is flagged on the proposal.
        $flag = ActivityLog::where('action', 'proposal.lead_match_ambiguous')
            ->where('entity_id', $proposal->id)->firstOrFail();
        $this->assertSame(2, $flag->after['count']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $flag->after['candidate_lead_ids']);
    }

    public function test_acceptance_does_not_transition_an_already_converted_lead(): void
    {
        [, $raw] = $this->sentProposal('converted@acme.test');
        $existingCustomer = Customer::create(['name' => 'Existing', 'pipeline_stage' => 'active']);
        // A converted lead (customer_id set) with the matching email — excluded.
        $converted = $this->lead('proposal', [
            'email' => 'converted@acme.test', 'customer_id' => $existingCustomer->id,
        ]);

        $this->accept($raw);

        // Unchanged, and no ambiguity flag (it was never a candidate).
        $this->assertSame('proposal', $converted->fresh()->status);
        $this->assertDatabaseMissing('activity_log', ['action' => 'lead.status_changed']);
        $this->assertDatabaseMissing('activity_log', ['action' => 'proposal.lead_match_ambiguous']);
    }
}
