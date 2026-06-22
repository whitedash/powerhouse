<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Form;
use App\Models\FormSubmissionDraft;
use App\Models\PortalUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The server-side per-step gate on the AUTHENTICATED Portal draft path
 * (PUT /portal/forms/{id}/draft, resume keyed on portal_user_id).
 *
 * The gate is opt-in: a forward "Next" sends advance:true and validates ONLY
 * the fields on the step being LEFT; save-for-later omits advance and never
 * validates. Final submit (POST .../draft/submit) still validates the whole
 * form. See app/Services/FormService::saveStep + app/Support/FormFieldRules.
 */
class PortalPerStepValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two-step form: step 0 carries a required `full_name`; step 1 a required
     * `email`. Advancing FROM step 0 sends step:1, so only `full_name` is gated.
     */
    private function twoStepForm(): Form
    {
        $form = Form::create([
            'name' => 'Onboarding',
            'slug' => 'onboarding-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'submit_button_text' => 'Submit',
            'webhook_secret' => Str::random(32),
            'created_by' => User::factory()->create()->id,
        ]);

        $stepOne = $form->steps()->create(['label' => 'Details', 'sort_order' => 0]);
        $stepTwo = $form->steps()->create(['label' => 'Contact', 'sort_order' => 1]);

        $form->fields()->create([
            'form_step_id' => $stepOne->id,
            'label' => 'Full name', 'field_key' => 'full_name', 'type' => 'text',
            'is_required' => true, 'width' => 'full', 'sort_order' => 0,
        ]);
        $form->fields()->create([
            'form_step_id' => $stepTwo->id,
            'label' => 'Email', 'field_key' => 'email', 'type' => 'email',
            'is_required' => true, 'width' => 'full', 'sort_order' => 0,
        ]);

        return $form;
    }

    private function portalUser(): PortalUser
    {
        return PortalUser::create([
            'customer_id' => Customer::create(['name' => 'Acme '.uniqid()])->id,
            'name' => 'Portal '.uniqid(),
            'email' => 'p'.uniqid().'@test.test',
            'password' => bcrypt('secret-pass-123'),
        ]);
    }

    private function draftFor(Form $form, PortalUser $user, array $data = []): FormSubmissionDraft
    {
        return FormSubmissionDraft::create([
            'form_id' => $form->id,
            'draft_token' => 'tok-'.Str::random(32),
            'portal_user_id' => $user->id,
            'current_step' => 0,
            'data' => $data,
            'expires_at' => null,
        ]);
    }

    public function test_advance_with_invalid_current_step_field_returns_422_and_does_not_advance(): void
    {
        $form = $this->twoStepForm();
        $user = $this->portalUser();
        $draft = $this->draftFor($form, $user);

        // Advance FROM step 0 (step:1, advance:true) with full_name (step 0,
        // required) left blank — the gate must reject it.
        $res = $this->actingAs($user, 'portal')->putJson("/portal/forms/{$form->id}/draft", [
            'step' => 1,
            'advance' => true,
            'answers' => ['email' => 'later@example.com'],
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['full_name']);

        // The resume marker stays on the failing step...
        $this->assertSame(0, $draft->fresh()->current_step);
        // ...but the respondent's input is preserved (persist-then-validate).
        $this->assertSame('later@example.com', $draft->fresh()->data['email'] ?? null);
    }

    public function test_valid_step_advances(): void
    {
        $form = $this->twoStepForm();
        $user = $this->portalUser();
        $draft = $this->draftFor($form, $user);

        $res = $this->actingAs($user, 'portal')->putJson("/portal/forms/{$form->id}/draft", [
            'step' => 1,
            'advance' => true,
            'answers' => ['full_name' => 'Dana Scully'],
        ]);

        $res->assertOk();
        $res->assertJson(['ok' => true, 'current_step' => 1]);
        $this->assertSame(1, $draft->fresh()->current_step);
    }

    public function test_save_for_later_does_not_validate(): void
    {
        $form = $this->twoStepForm();
        $user = $this->portalUser();
        $this->draftFor($form, $user);

        // No advance flag: save-for-later persists incomplete answers (full_name
        // blank) without ever running the gate.
        $res = $this->actingAs($user, 'portal')->putJson("/portal/forms/{$form->id}/draft", [
            'step' => 1,
            'answers' => ['email' => 'partial@example.com'],
        ]);

        $res->assertOk();
        $res->assertJson(['ok' => true]);
    }

    public function test_later_step_required_field_does_not_block_earlier_advance(): void
    {
        $form = $this->twoStepForm();
        $user = $this->portalUser();
        $this->draftFor($form, $user);

        // Advancing from step 0 with only full_name: email (step 1, required) is
        // absent yet the advance succeeds — the gate sees only step 0's fields.
        $res = $this->actingAs($user, 'portal')->putJson("/portal/forms/{$form->id}/draft", [
            'step' => 1,
            'advance' => true,
            'answers' => ['full_name' => 'Dana Scully'],
        ]);

        $res->assertOk();
    }

    public function test_final_submit_still_validates_whole_form(): void
    {
        $form = $this->twoStepForm();
        $user = $this->portalUser();
        // Draft carries step 0's answer but NOT step 1's required email.
        $this->draftFor($form, $user, ['full_name' => 'Dana Scully']);

        $res = $this->actingAs($user, 'portal')->postJson("/portal/forms/{$form->id}/draft/submit");

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['email']);
    }

    /**
     * Regression guard for the off-step Submit error: save-for-later (no advance
     * flag) persists without the gate AND bumps the resume marker past the
     * unvalidated step, so the whole-form Submit can 422 on a field belonging to
     * an EARLIER step than the one the respondent is parked on. The server must
     * surface that off-step field_key, which is what the client (Fill.vue
     * earliestErrorStep) resolves to jump back to the offending step — otherwise
     * Submit is a silent no-op. This asserts the server-side precondition.
     */
    public function test_save_for_later_advances_marker_then_submit_surfaces_off_step_error(): void
    {
        $form = $this->twoStepForm();
        $user = $this->portalUser();
        $draft = $this->draftFor($form, $user);

        // Save-for-later from step 0 with full_name (step 0, required) left blank.
        // No advance flag → no per-step gate → persists and advances the marker.
        $save = $this->actingAs($user, 'portal')->putJson("/portal/forms/{$form->id}/draft", [
            'step' => 1,
            'answers' => ['email' => 'dana@example.com'],
        ]);
        $save->assertOk();
        // The resume marker is now on the last step, past the unvalidated step 0.
        $this->assertSame(1, $draft->fresh()->current_step);

        // Whole-form Submit 422s on full_name — a step-0 field, NOT on the last
        // step the marker points at. The off-step key must be present for the
        // client jump to resolve.
        $submit = $this->actingAs($user, 'portal')->postJson("/portal/forms/{$form->id}/draft/submit");
        $submit->assertStatus(422);
        $submit->assertJsonValidationErrors(['full_name']);
    }
}
