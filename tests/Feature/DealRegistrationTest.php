<?php

namespace Tests\Feature;

use App\Enums\ReferralStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Referrer;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\DealRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function referrer(): Referrer
    {
        return Referrer::factory()->create();
    }

    /** Register happy-path: pending_review, referrer stamped from AUTH not input. */
    public function test_register_creates_pending_review_deal_stamped_to_authenticated_referrer(): void
    {
        $referrer = $this->referrer();
        $otherReferrer = $this->referrer();

        $this->actingAs($referrer->user)
            ->post('/referrer/referrals', [
                // Attempt to spoof a different referrer — must be ignored.
                'referrer_id' => $otherReferrer->id,
                'company' => 'Acme Bistro',
                'contact_name' => 'Dana Cook',
                'email' => 'dana@acmebistro.test',
                'phone' => '+44 7700 900111',
                'notes' => 'Met at a trade show.',
                'consent' => true,
            ])
            ->assertRedirect();

        $lead = Lead::where('email', 'dana@acmebistro.test')->firstOrFail();
        $this->assertSame($referrer->id, $lead->referrer_id, 'referrer_id must come from auth, never input');
        $this->assertNotSame($otherReferrer->id, $lead->referrer_id);
        $this->assertSame(ReferralStatus::PendingReview, $lead->referral_status);
        $this->assertSame('referral', $lead->source);
        $this->assertSame('new', $lead->status);
        $this->assertNotNull($lead->registered_at);
        $this->assertNotNull($lead->referral_consent_at);
        $this->assertSame('Dana', $lead->first_name);
        $this->assertSame('Cook', $lead->last_name);
    }

    /** Missing GDPR consent is rejected. */
    public function test_register_requires_consent(): void
    {
        $referrer = $this->referrer();

        $this->actingAs($referrer->user)
            ->post('/referrer/referrals', [
                'contact_name' => 'No Consent',
                'email' => 'noconsent@test.test',
                'consent' => false,
            ])
            ->assertSessionHasErrors('consent');

        $this->assertDatabaseMissing('leads', ['email' => 'noconsent@test.test']);
    }

    /** Dedup: an existing email blocks registration with a NON-REVEALING reason. */
    public function test_dedup_blocks_existing_email_with_non_revealing_reason(): void
    {
        $referrer = $this->referrer();

        // Existing customer contact — the "secret" record we must not leak.
        $customer = Company::create(['name' => 'Existing Co']);
        Contact::create([
            'customer_id' => $customer->id,
            'name' => 'Secret Person',
            'email' => 'taken@existing.test',
        ]);

        $response = $this->actingAs($referrer->user)
            ->post('/referrer/referrals', [
                'company' => 'New Pitch Ltd',
                'contact_name' => 'Some One',
                'email' => 'taken@existing.test',
                'consent' => true,
            ]);

        $response->assertSessionHasErrors('email');

        // No deal created for this referrer.
        $this->assertDatabaseMissing('leads', ['referrer_id' => $referrer->id]);

        // Non-revealing: the message must NOT leak the existing record.
        $message = session('errors')->get('email')[0] ?? '';
        $this->assertStringNotContainsString('Secret Person', $message);
        $this->assertStringNotContainsString('Existing Co', $message);
    }

    /** Dedup does NOT block a referrer re-touching their OWN prior registration. */
    public function test_dedup_allows_referrers_own_prior_registration(): void
    {
        $referrer = $this->referrer();
        $service = app(DealRegistrationService::class);

        $service->register($referrer, ['contact_name' => 'Repeat Lead', 'email' => 'mine@own.test', 'consent' => true]);
        // Same referrer, same email again — allowed (their own lead).
        $second = $service->register($referrer, ['contact_name' => 'Repeat Lead', 'email' => 'mine@own.test', 'consent' => true]);

        $this->assertSame($referrer->id, $second->referrer_id);
        $this->assertSame(2, Lead::where('email', 'mine@own.test')->count());
    }

    /** Approve sets approved + protected_until ≈ now + 90 days. */
    public function test_approve_sets_protected_until_90_days(): void
    {
        $referrer = $this->referrer();
        $staff = User::factory()->create(['role' => 'staff']);
        $lead = app(DealRegistrationService::class)
            ->register($referrer, ['contact_name' => 'Pat Lead', 'email' => 'pat@deal.test', 'consent' => true]);

        app(DealRegistrationService::class)->approve($lead->fresh(), $staff);

        $lead->refresh();
        $this->assertSame(ReferralStatus::Approved, $lead->referral_status);
        $this->assertSame($staff->id, $lead->reviewed_by);
        $this->assertNotNull($lead->protected_until);
        // ~90 days from now (clock starts at approval).
        $this->assertEqualsWithDelta(90, now()->diffInDays($lead->protected_until, false), 1);
    }

    /** Reject sets rejected + stores the reason. */
    public function test_reject_sets_rejected_with_reason(): void
    {
        $referrer = $this->referrer();
        $staff = User::factory()->create(['role' => 'staff']);
        $lead = app(DealRegistrationService::class)
            ->register($referrer, ['contact_name' => 'Rej Lead', 'email' => 'rej@deal.test', 'consent' => true]);

        app(DealRegistrationService::class)->reject($lead->fresh(), $staff, 'Already an active customer.');

        $lead->refresh();
        $this->assertSame(ReferralStatus::Rejected, $lead->referral_status);
        $this->assertSame('Already an active customer.', $lead->review_notes);
        $this->assertNull($lead->protected_until);
    }

    /** attributeFromLead skips rejected and expired; attributes approved. */
    public function test_attribution_skips_rejected_and_expired(): void
    {
        $referrer = $this->referrer();
        $attr = app(AttributionService::class);

        foreach ([ReferralStatus::Rejected, ReferralStatus::Expired] as $status) {
            $customer = Company::create(['name' => 'Cust '.$status->value]);
            $lead = Lead::create([
                'first_name' => 'X',
                'email' => 'x'.$status->value.'@t.test',
                'source' => 'referral',
                'status' => 'new',
                'referrer_id' => $referrer->id,
                'referral_status' => $status,
                'created_by' => $referrer->user_id,
            ]);

            $this->assertNull($attr->attributeFromLead($customer, $lead));
            $this->assertDatabaseMissing('customer_referrals', ['customer_id' => $customer->id]);
        }

        // Approved still attributes.
        $customer = Company::create(['name' => 'Cust approved']);
        $lead = Lead::create([
            'first_name' => 'Y',
            'email' => 'y@t.test',
            'source' => 'referral',
            'status' => 'new',
            'referrer_id' => $referrer->id,
            'referral_status' => ReferralStatus::Approved,
            'created_by' => $referrer->user_id,
        ]);
        $this->assertNotNull($attr->attributeFromLead($customer, $lead));
        $this->assertDatabaseHas('customer_referrals', [
            'customer_id' => $customer->id,
            'referrer_id' => $referrer->id,
        ]);
    }

    /** A referrer sees only their OWN deals (IDOR-safe). */
    public function test_referrer_sees_only_their_own_deals(): void
    {
        $a = $this->referrer();
        $b = $this->referrer();
        $service = app(DealRegistrationService::class);

        $service->register($a, ['company' => 'Alpha Deal Co', 'contact_name' => 'A One', 'email' => 'a@deal.test', 'consent' => true]);
        $service->register($b, ['company' => 'Bravo Deal Co', 'contact_name' => 'B One', 'email' => 'b@deal.test', 'consent' => true]);

        $this->actingAs($a->user)
            ->get('/referrer/referrals')
            ->assertOk()
            ->assertSee('Alpha Deal Co')
            ->assertDontSee('Bravo Deal Co');
    }
}
