<?php

namespace Tests\Feature;

use App\Mail\ProposalRejectedCustomer;
use App\Mail\ProposalRejectedStaff;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\ProposalRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Public proposal-rejection flow — mirror of ProposalAcceptanceSecurityTest.
 * Same token (the acceptance link doubles as the reject link), hashed at rest,
 * constant-time re-verified, same throttle tiers (GET 30/min, POST 6/min).
 * Plus the proposal -> lost lead automation and the rejection notifications.
 */
class ProposalRejectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Throttle state lives in the (array) cache — clear it so each test
        // starts with a fresh window regardless of order.
        RateLimiter::clear('');
        app('cache')->store()->flush();
    }

    /**
     * A sent proposal whose customer has a primary contact (email drives the
     * lead-match path). $contactEmail null → no primary contact.
     */
    private function sentProposal(string $token, ?string $contactEmail = null): Proposal
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'email' => 'creator@wd.test']);
        $customer = Company::create(['name' => 'Acme Co', 'pipeline_stage' => 'active']);

        if ($contactEmail !== null) {
            Contact::create([
                'customer_id' => $customer->id,
                'name' => 'Pat Buyer',
                'email' => $contactEmail,
                'role' => 'owner',
                'is_primary' => true,
            ]);
        }

        return Proposal::create([
            'customer_id' => $customer->id,
            'reference' => 'PROP-2026-0001',
            'title' => 'Website build',
            'status' => 'sent',
            'subtotal' => 1000,
            'vat_rate' => 20,
            'vat_amount' => 200,
            'total' => 1200,
            // Hashed at rest — tests hit the route with the raw $token, which
            // the lookup hashes to match (same as the accept flow).
            'acceptance_token' => hash('sha256', $token),
            'acceptance_token_expires_at' => now()->addDays(30),
            'created_by' => $admin->id,
        ]);
    }

    private function token(string $seed = 'a'): string
    {
        return hash('sha256', $seed);
    }

    public function test_valid_token_renders_the_reject_page(): void
    {
        $token = $this->token('valid');
        $this->sentProposal($token);

        $this->get("/proposals/reject/{$token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/ProposalReject')
                ->where('proposal.reference', 'PROP-2026-0001'));
    }

    public function test_valid_rejection_sets_fields_and_nulls_the_token(): void
    {
        $token = $this->token('reject');
        $proposal = $this->sentProposal($token);

        $this->post("/proposals/reject/{$token}", [
            'rejection_reason' => 'Went with another supplier',
        ])->assertOk()->assertInertia(fn ($page) => $page->component('Public/ProposalRejected'));

        $proposal->refresh();
        $this->assertSame('rejected', $proposal->status);
        $this->assertNotNull($proposal->rejected_at);
        $this->assertSame('Went with another supplier', $proposal->rejection_reason);
        $this->assertNull($proposal->acceptance_token, 'token is single-use — nulled on reject');

        $this->assertDatabaseHas('activity_log', [
            'action' => 'proposal.rejected',
            'entity_type' => 'proposal',
            'entity_id' => $proposal->id,
            'user_role' => 'guest',
        ]);
    }

    public function test_rejection_reason_is_optional(): void
    {
        $token = $this->token('no-reason');
        $proposal = $this->sentProposal($token);

        $this->post("/proposals/reject/{$token}", [])->assertOk();

        $proposal->refresh();
        $this->assertSame('rejected', $proposal->status);
        $this->assertNull($proposal->rejection_reason);
    }

    public function test_a_nulled_token_never_matches(): void
    {
        // Once rejected (or accepted), the token is nulled — a re-visit 404s,
        // exactly as the accept flow behaves.
        $proposal = $this->sentProposal($this->token('live'));
        $proposal->update(['status' => 'rejected', 'acceptance_token' => null]);

        $this->get('/proposals/reject/'.$this->token('live'))->assertNotFound();
        $this->post('/proposals/reject/'.$this->token('live'), [])->assertNotFound();
    }

    public function test_unknown_token_is_rejected_404(): void
    {
        $this->sentProposal($this->token('real'));
        $this->get('/proposals/reject/'.$this->token('wrong'))->assertNotFound();
    }

    public function test_get_route_is_throttled_after_30_requests_per_minute(): void
    {
        $token = $this->token('throttle-get');
        $this->sentProposal($token);

        for ($i = 0; $i < 30; $i++) {
            $this->get("/proposals/reject/{$token}")->assertOk();
        }
        $this->get("/proposals/reject/{$token}")->assertStatus(429);
    }

    public function test_post_route_is_throttled_after_6_requests_per_minute(): void
    {
        $token = $this->token('throttle-post');
        $this->sentProposal($token);

        // First reject nulls the token; subsequent POSTs 404 — but all count
        // toward the limiter, so the 7th is a 429 regardless of body outcome.
        for ($i = 0; $i < 6; $i++) {
            $this->post("/proposals/reject/{$token}", []);
        }
        $this->post("/proposals/reject/{$token}", [])->assertStatus(429);
    }

    public function test_matched_unconverted_proposal_lead_transitions_to_lost(): void
    {
        $token = $this->token('lead-lost');
        $proposal = $this->sentProposal($token, 'buyer@acme.test');
        $lead = Lead::create([
            'first_name' => 'Pat', 'email' => 'buyer@acme.test',
            'status' => 'proposal', 'created_by' => $proposal->created_by,
        ]);

        $this->post("/proposals/reject/{$token}", [])->assertOk();

        $this->assertSame('lost', $lead->fresh()->status);
        $this->assertDatabaseHas('activity_log', [
            'action' => 'lead.status_changed',
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'user_role' => 'system',
        ]);
        // The audit row records the via: 'proposal_rejected' provenance.
        $log = ActivityLog::where('action', 'lead.status_changed')->where('entity_id', $lead->id)->firstOrFail();
        $this->assertSame('proposal_rejected', $log->after['via']);
        $this->assertSame('lost', $log->after['to']);
    }

    public function test_wrong_status_lead_does_not_transition(): void
    {
        $token = $this->token('lead-wrongstatus');
        $proposal = $this->sentProposal($token, 'new@acme.test');
        // A lead not in 'proposal' status must be left alone.
        $lead = Lead::create([
            'first_name' => 'New', 'email' => 'new@acme.test',
            'status' => 'contacted', 'created_by' => $proposal->created_by,
        ]);

        $this->post("/proposals/reject/{$token}", [])->assertOk();

        $this->assertSame('contacted', $lead->fresh()->status, 'only proposal -> lost transitions');
    }

    public function test_converted_lead_does_not_transition(): void
    {
        $token = $this->token('lead-converted');
        $proposal = $this->sentProposal($token, 'conv@acme.test');
        $otherCustomer = Company::create(['name' => 'Other', 'pipeline_stage' => 'active']);
        // Already-converted lead (customer_id set) is not a candidate.
        $lead = Lead::create([
            'first_name' => 'Conv', 'email' => 'conv@acme.test',
            'status' => 'proposal', 'customer_id' => $otherCustomer->id,
            'created_by' => $proposal->created_by,
        ]);

        $this->post("/proposals/reject/{$token}", [])->assertOk();

        $this->assertSame('proposal', $lead->fresh()->status, 'converted leads are not candidates');
    }

    public function test_ambiguous_matches_flag_rather_than_pick(): void
    {
        $token = $this->token('lead-ambiguous');
        $proposal = $this->sentProposal($token, 'dup@acme.test');
        // Two unconverted proposal-status leads on the same email → ambiguous.
        $a = Lead::create(['first_name' => 'A', 'email' => 'dup@acme.test', 'status' => 'proposal', 'created_by' => $proposal->created_by]);
        $b = Lead::create(['first_name' => 'B', 'email' => 'dup@acme.test', 'status' => 'proposal', 'created_by' => $proposal->created_by]);

        $this->post("/proposals/reject/{$token}", [])->assertOk();

        // Neither transitions; an ambiguity row is written instead.
        $this->assertSame('proposal', $a->fresh()->status);
        $this->assertSame('proposal', $b->fresh()->status);
        $this->assertDatabaseHas('activity_log', [
            'action' => 'proposal.lead_match_ambiguous',
            'entity_type' => 'proposal',
            'entity_id' => $proposal->id,
        ]);
    }

    public function test_notifications_fire_on_rejection(): void
    {
        Notification::fake();
        Mail::fake();

        $token = $this->token('notify');
        $proposal = $this->sentProposal($token, 'buyer@acme.test');
        $creator = User::find($proposal->created_by);

        $this->post("/proposals/reject/{$token}", [])->assertOk();

        Notification::assertSentTo($creator, ProposalRejected::class);
        Mail::assertSent(ProposalRejectedStaff::class);
        Mail::assertSent(ProposalRejectedCustomer::class);
    }
}
