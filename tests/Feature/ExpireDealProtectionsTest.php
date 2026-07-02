<?php

namespace Tests\Feature;

use App\Enums\ReferralStatus;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ExpireDealProtectionsTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = User::factory()->create()->id;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function lead(array $attrs): Lead
    {
        return Lead::create(array_merge([
            'first_name' => 'Deal',
            'source' => 'referral',
            'status' => 'new',
            'created_by' => $this->userId,
        ], $attrs));
    }

    private function runCommand(): void
    {
        Artisan::call('referrals:expire-protections');
    }

    public function test_approved_past_due_open_deal_flips_to_expired(): void
    {
        $lead = $this->lead([
            'referral_status' => ReferralStatus::Approved,
            'protected_until' => now()->subDay(),
        ]);

        $this->runCommand();

        $lead->refresh();
        $this->assertSame(ReferralStatus::Expired, $lead->referral_status);
        // protected_until is left as the historical date.
        $this->assertNotNull($lead->protected_until);
        $this->assertTrue($lead->protected_until->isPast());
        $this->assertDatabaseHas('activity_log', [
            'action' => 'referral.deal_expired',
            'entity_id' => $lead->id,
        ]);
    }

    public function test_won_deal_is_untouched(): void
    {
        $lead = $this->lead([
            'status' => 'won',
            'referral_status' => ReferralStatus::Approved,
            'protected_until' => now()->subDay(),
        ]);

        $this->runCommand();

        $this->assertSame(ReferralStatus::Approved, $lead->refresh()->referral_status);
    }

    public function test_converted_deal_is_untouched(): void
    {
        // customer_id present = converted/closed → earned, never expire.
        $customerId = Company::create(['name' => 'Converted Co'])->id;
        $lead = $this->lead([
            'referral_status' => ReferralStatus::Approved,
            'protected_until' => now()->subDay(),
            'customer_id' => $customerId,
        ]);

        $this->runCommand();

        $this->assertSame(ReferralStatus::Approved, $lead->refresh()->referral_status);
    }

    public function test_pending_review_and_null_are_untouched(): void
    {
        $pending = $this->lead([
            'referral_status' => ReferralStatus::PendingReview,
            'protected_until' => now()->subDay(),
        ]);
        // A plain (non-deal-registration) lead — no referral_status.
        $plain = $this->lead([
            'protected_until' => now()->subDay(),
        ]);

        $this->runCommand();

        $this->assertSame(ReferralStatus::PendingReview, $pending->refresh()->referral_status);
        $this->assertNull($plain->refresh()->referral_status);
    }

    public function test_approved_not_yet_due_is_untouched(): void
    {
        $lead = $this->lead([
            'referral_status' => ReferralStatus::Approved,
            'protected_until' => now()->addDays(30),
        ]);

        $this->runCommand();

        $this->assertSame(ReferralStatus::Approved, $lead->refresh()->referral_status);
    }

    public function test_running_twice_is_a_no_op(): void
    {
        $lead = $this->lead([
            'referral_status' => ReferralStatus::Approved,
            'protected_until' => now()->subDay(),
        ]);

        $this->runCommand();
        $this->runCommand();

        $this->assertSame(ReferralStatus::Expired, $lead->refresh()->referral_status);
        // Idempotent: the second pass neither re-flips nor re-logs.
        $this->assertSame(1, ActivityLog::where('action', 'referral.deal_expired')
            ->where('entity_id', $lead->id)
            ->count());
    }
}
