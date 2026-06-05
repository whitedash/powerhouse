<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Lead;
use App\Models\Referrer;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralAttributionTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'ABCDEFGH';

    private function makeReferrer(): Referrer
    {
        $user = User::factory()->create(['role' => 'referrer', 'email' => 'partner@example.com']);

        return Referrer::create([
            'user_id' => $user->id,
            'referral_code' => self::CODE,
            'is_active' => true,
        ]);
    }

    /**
     * A "create lead" workflow bound to the public form-submitted trigger,
     * mirroring the real lead-capture path.
     */
    private function makeLeadCaptureForm(User $owner): Form
    {
        $form = Form::create([
            'name' => 'Join',
            'slug' => 'join',
            'status' => 'active',
            'webhook_secret' => 'test-secret',
            // forms.created_by is NOT NULL (FK→users); MySQL strict mode
            // rejects the insert without it.
            'created_by' => $owner->id,
        ]);

        $workflow = Workflow::create([
            'name' => 'Form → Lead',
            'is_active' => true,
            'trigger_type' => 'form_submitted',
            'created_by' => $owner->id,
        ]);

        WorkflowAction::create([
            'workflow_id' => $workflow->id,
            'action_type' => 'create_lead',
            // created_by must be set explicitly: actionCreateLead falls back to
            // user id 1 (the seeded platform owner) otherwise, which doesn't
            // exist under RefreshDatabase, so the lead insert would FK-fail.
            'config' => ['source' => 'referral', 'created_by' => $owner->id],
            'sort_order' => 0,
        ]);

        return $form;
    }

    public function test_redirect_logs_a_click_and_sets_the_cookie(): void
    {
        $referrer = $this->makeReferrer();

        $response = $this->get('/r/'.self::CODE.'?p=maavelus&c=spring');

        $response->assertRedirect();
        $this->assertStringContainsString('ref='.self::CODE, $response->headers->get('Location'));
        $response->assertCookie('wd_ref');

        $this->assertDatabaseHas('referral_clicks', [
            'referrer_id' => $referrer->id,
            'referral_code' => self::CODE,
            'product' => 'maavelus',
            'campaign' => 'spring',
        ]);
    }

    public function test_unknown_code_redirects_without_logging_a_click(): void
    {
        $this->makeReferrer();

        $this->get('/r/ZZZZZZZZ')->assertRedirect();

        $this->assertDatabaseCount('referral_clicks', 0);
    }

    public function test_form_submission_with_ref_creates_a_lead_carrying_the_referrer(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $referrer = $this->makeReferrer();
        $this->makeLeadCaptureForm($owner);

        $this->post('/forms/join/submit?ref='.self::CODE, [
            'first_name' => 'Lead',
            'email' => 'newlead@example.com',
        ]);

        $lead = Lead::where('email', 'newlead@example.com')->first();

        $this->assertNotNull($lead);
        $this->assertSame($referrer->id, $lead->referrer_id);
        $this->assertSame(self::CODE, $lead->referral_code);
    }

    public function test_converting_a_referred_lead_creates_a_customer_referral(): void
    {
        $staff = User::factory()->create(['role' => 'super_admin']);
        $referrer = $this->makeReferrer();
        $this->makeLeadCaptureForm($staff);

        // Submission carrying ?ref produces a lead with the referrer.
        $this->post('/forms/join/submit?ref='.self::CODE, [
            'first_name' => 'Lead',
            'email' => 'newlead@example.com',
        ]);
        $lead = Lead::where('email', 'newlead@example.com')->firstOrFail();

        // Convert the lead → the attribution row should be written.
        $this->actingAs($staff)->post('/leads/'.$lead->id.'/convert', [
            'name' => 'Lead Co',
            'type' => 'restaurant',
            'address_line1' => '1 High St',
            'city' => 'London',
            'postcode' => 'EC1A 1AA',
        ]);

        $lead->refresh();
        $this->assertNotNull($lead->customer_id);

        $this->assertDatabaseHas('customer_referrals', [
            'customer_id' => $lead->customer_id,
            'referrer_id' => $referrer->id,
            'lead_id' => $lead->id,
            'source' => 'param',
        ]);
    }

    public function test_self_referral_is_blocked(): void
    {
        $staff = User::factory()->create(['role' => 'super_admin']);
        $referrer = $this->makeReferrer(); // partner@example.com
        $this->makeLeadCaptureForm($staff);

        // The lead uses the referrer's OWN email — self-referral.
        $this->post('/forms/join/submit?ref='.self::CODE, [
            'first_name' => 'Self',
            'email' => 'partner@example.com',
        ]);
        $lead = Lead::where('email', 'partner@example.com')->firstOrFail();

        $this->actingAs($staff)->post('/leads/'.$lead->id.'/convert', [
            'name' => 'Self Co',
            'type' => 'other',
            'address_line1' => '1 High St',
            'city' => 'London',
            'postcode' => 'EC1A 1AA',
        ]);

        $lead->refresh();
        $this->assertDatabaseMissing('customer_referrals', [
            'customer_id' => $lead->customer_id,
        ]);
    }
}
