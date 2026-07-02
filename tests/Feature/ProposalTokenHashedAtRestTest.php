<?php

namespace Tests\Feature;

use App\Mail\ProposalSent;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Acceptance tokens are hashed at rest. The raw 256-bit token travels only in
 * the outgoing email / one-shot regenerate reveal; storage holds
 * hash('sha256', raw). The public lookup hashes the incoming URL token and
 * matches on the hash, so a DB read never yields a usable link and no read
 * endpoint returns a raw token.
 */
class ProposalTokenHashedAtRestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function proposal(string $status, array $overrides = []): Proposal
    {
        $customer = Company::create(['name' => 'Acme Co', 'pipeline_stage' => 'active']);

        return Proposal::create(array_merge([
            'customer_id' => $customer->id,
            'reference' => 'PROP-2026-'.substr(md5(uniqid()), 0, 4),
            'title' => 'Website build',
            'status' => $status,
            'subtotal' => 1000, 'vat_rate' => 20, 'vat_amount' => 200, 'total' => 1200,
            'created_by' => $this->admin()->id,
        ], $overrides));
    }

    public function test_send_stores_a_hash_and_emails_the_raw_link_which_resolves(): void
    {
        Mail::fake();
        $customer = Company::create(['name' => 'Acme Co', 'pipeline_stage' => 'active']);
        Contact::create([
            'customer_id' => $customer->id, 'name' => 'Pat', 'email' => 'pat@acme.test',
            'role' => 'owner', 'is_primary' => true,
        ]);
        $proposal = Proposal::create([
            'customer_id' => $customer->id, 'reference' => 'PROP-2026-9001',
            'title' => 'Build', 'status' => 'draft',
            'subtotal' => 1000, 'vat_rate' => 20, 'vat_amount' => 200, 'total' => 1200,
            'created_by' => $this->admin()->id,
        ]);

        $this->actingAs($this->admin())->post("/proposals/{$proposal->id}/send")->assertRedirect();

        // Stored value is NOT a raw token that appears in the email — it's its hash.
        $rawToken = null;
        Mail::assertSent(ProposalSent::class, function (ProposalSent $mail) use (&$rawToken) {
            $rawToken = $mail->rawAcceptToken;

            return true;
        });
        $this->assertNotNull($rawToken);
        $stored = $proposal->fresh()->acceptance_token;
        $this->assertNotSame($rawToken, $stored, 'the raw token is never persisted');
        $this->assertSame(hash('sha256', $rawToken), $stored, 'storage holds the hash of the raw');

        // The raw link from the email resolves through the public lookup.
        $this->get("/proposals/accept/{$rawToken}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Public/ProposalView'));
    }

    public function test_an_outstanding_raw_token_still_resolves_after_the_migration(): void
    {
        // Simulate a proposal minted BEFORE this change: the raw token was
        // stored verbatim. The customer holds that raw token in their email.
        $rawToken = hash('sha256', 'legacy-outstanding-token');
        $proposal = $this->proposal('sent', [
            'acceptance_token' => $rawToken, // pre-migration: raw stored verbatim
            'acceptance_token_expires_at' => now()->addDays(10),
        ]);

        // Apply exactly what the data migration does to live rows.
        DB::statement('UPDATE proposals SET acceptance_token = SHA2(acceptance_token, 256) WHERE acceptance_token IS NOT NULL');

        // The stored value is now the hash; the customer's un-changed raw link
        // still resolves (proves no outstanding proposal was stranded).
        $this->assertSame(hash('sha256', $rawToken), $proposal->fresh()->acceptance_token);
        $this->get("/proposals/accept/{$rawToken}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('proposal.reference', $proposal->reference));
    }

    public function test_internal_show_never_returns_a_raw_token(): void
    {
        $proposal = $this->proposal('sent', [
            'acceptance_token' => hash('sha256', hash('sha256', 'x')),
            'acceptance_token_expires_at' => now()->addDays(10),
        ]);

        $this->actingAs($this->admin())
            ->get("/proposals/{$proposal->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Internal/Proposals/Show')
                ->missing('proposal.acceptance_token'));
    }

    public function test_regenerate_rotates_the_link_and_reveals_it_once(): void
    {
        $oldStored = hash('sha256', 'old-raw');
        $proposal = $this->proposal('sent', [
            'acceptance_token' => $oldStored,
            'acceptance_token_expires_at' => now()->addDays(10),
        ]);

        $response = $this->actingAs($this->admin())
            ->post("/proposals/{$proposal->id}/regenerate-link")
            ->assertRedirect();

        // A fresh raw URL is flashed exactly once.
        $revealed = session('proposal_link');
        $this->assertNotNull($revealed);
        $rawToken = basename(parse_url($revealed, PHP_URL_PATH));

        // Stored value rotated to the hash of the new raw; the old link is dead.
        $newStored = $proposal->fresh()->acceptance_token;
        $this->assertSame(hash('sha256', $rawToken), $newStored);
        $this->assertNotSame($oldStored, $newStored, 'the previous link no longer works');

        // The revealed raw link resolves; the old one 404s.
        $this->get($revealed)->assertOk();
        $this->get('/proposals/accept/'.hash('sha256', 'old-raw'))->assertNotFound();
    }

    public function test_regenerate_is_rejected_for_a_non_sent_proposal(): void
    {
        $proposal = $this->proposal('draft');

        $this->actingAs($this->admin())
            ->post("/proposals/{$proposal->id}/regenerate-link")
            ->assertRedirect();

        $this->assertNull(session('proposal_link'));
        $this->assertNull($proposal->fresh()->acceptance_token);
    }
}
