<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Public proposal-acceptance hardening: the token lookup is constant-time
 * re-verified (hash_equals), and both routes are rate-limited (GET 30/min,
 * POST 6/min) like the app's other public token surfaces. The 64-hex token
 * stays plaintext-at-rest for now — hashing it is deferred (it would break
 * the internal "copy acceptance link" affordance; see the report).
 */
class ProposalAcceptanceSecurityTest extends TestCase
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

    private function sentProposal(string $token): Proposal
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $customer = Company::create(['name' => 'Acme Co', 'pipeline_stage' => 'active']);

        return Proposal::create([
            'customer_id' => $customer->id,
            'reference' => 'PROP-2026-0001',
            'title' => 'Website build',
            'status' => 'sent',
            'subtotal' => 1000,
            'vat_rate' => 20,
            'vat_amount' => 200,
            'total' => 1200,
            // Tokens are hashed at rest (merged from fix/proposal-token-hash-at-rest):
            // store the hash; tests hit the route with the raw $token, which the
            // lookup hashes to match.
            'acceptance_token' => hash('sha256', $token),
            'acceptance_token_expires_at' => now()->addDays(30),
            'created_by' => $admin->id,
        ]);
    }

    private function token(string $seed = 'a'): string
    {
        // A well-formed 64-hex token matching the route constraint.
        return hash('sha256', $seed);
    }

    public function test_valid_token_renders_the_public_proposal(): void
    {
        $token = $this->token('valid');
        $this->sentProposal($token);

        $this->get("/proposals/accept/{$token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/ProposalView')
                ->where('proposal.reference', 'PROP-2026-0001'));
    }

    public function test_unknown_token_is_rejected_404(): void
    {
        $this->sentProposal($this->token('real'));

        // Well-formed but not a stored token → miss.
        $this->get('/proposals/accept/'.$this->token('wrong'))->assertNotFound();
    }

    public function test_a_nulled_token_never_matches(): void
    {
        // hash_equals must reject an empty/nulled stored token even against
        // a crafted request — the accepted proposal's link is dead.
        $proposal = $this->sentProposal($this->token('live'));
        $proposal->update(['status' => 'accepted', 'acceptance_token' => null]);

        $this->get('/proposals/accept/'.$this->token('live'))->assertNotFound();
    }

    public function test_valid_token_accepts_correctly(): void
    {
        $token = $this->token('accept');
        $proposal = $this->sentProposal($token);

        $this->post("/proposals/accept/{$token}", [
            'accepted_name' => 'Pat Buyer',
            'accepted_confirm' => true,
        ])->assertOk()->assertInertia(fn ($page) => $page->component('Public/ProposalAccepted'));

        $proposal->refresh();
        $this->assertSame('accepted', $proposal->status);
        $this->assertSame('Pat Buyer', $proposal->accepted_by_name);
        $this->assertNull($proposal->acceptance_token, 'token is single-use — nulled on accept');
    }

    public function test_get_route_is_throttled_after_30_requests_per_minute(): void
    {
        $token = $this->token('throttle-get');
        $this->sentProposal($token);

        for ($i = 0; $i < 30; $i++) {
            $this->get("/proposals/accept/{$token}")->assertOk();
        }
        // 31st within the window is blocked.
        $this->get("/proposals/accept/{$token}")->assertStatus(429);
    }

    public function test_post_route_is_throttled_after_6_requests_per_minute(): void
    {
        $token = $this->token('throttle-post');
        $this->sentProposal($token);

        // The first accept succeeds and nulls the token; subsequent POSTs
        // 410 (link no longer valid) — but all count toward the limiter, so
        // the 7th is a 429 regardless of body outcome.
        for ($i = 0; $i < 6; $i++) {
            $this->post("/proposals/accept/{$token}", [
                'accepted_name' => 'Pat',
                'accepted_confirm' => true,
            ]);
        }
        $this->post("/proposals/accept/{$token}", [
            'accepted_name' => 'Pat',
            'accepted_confirm' => true,
        ])->assertStatus(429);
    }
}
