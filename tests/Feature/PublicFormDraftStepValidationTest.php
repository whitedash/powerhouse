<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormSubmissionDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The server-side per-step gate on the ANONYMOUS embed draft path
 * (POST /forms/{slug}/draft → init; PUT /forms/{slug}/draft/{token} → save),
 * plus proof that a LEGACY single-step form (no step rows, fields with a NULL
 * form_step_id) submits exactly as before.
 *
 * Same opt-in contract as the Portal path: advance:true gates only the step
 * being left; save-for-later omits it. See FormService::saveStep.
 */
class PublicFormDraftStepValidationTest extends TestCase
{
    use RefreshDatabase;

    private function twoStepForm(): Form
    {
        $form = Form::create([
            'name' => 'Embed Onboarding',
            'slug' => 'embed-'.Str::lower(Str::random(6)),
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

    /** Init a draft via the public endpoint and return its token. */
    private function initDraft(Form $form): string
    {
        return $this->postJson("/forms/{$form->slug}/draft")->json('draft_token');
    }

    public function test_public_advance_with_invalid_current_step_field_returns_422_and_does_not_advance(): void
    {
        $form = $this->twoStepForm();
        $token = $this->initDraft($form);

        $res = $this->putJson("/forms/{$form->slug}/draft/{$token}", [
            'step' => 1,
            'advance' => true,
            'answers' => ['email' => 'later@example.com'],
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['full_name']);

        $draft = FormSubmissionDraft::where('draft_token', $token)->firstOrFail();
        $this->assertSame(0, $draft->current_step);
        $this->assertSame('later@example.com', $draft->data['email'] ?? null);
    }

    public function test_public_valid_step_advances(): void
    {
        $form = $this->twoStepForm();
        $token = $this->initDraft($form);

        $res = $this->putJson("/forms/{$form->slug}/draft/{$token}", [
            'step' => 1,
            'advance' => true,
            'answers' => ['full_name' => 'Dana Scully'],
        ]);

        $res->assertOk();
        $res->assertJson(['ok' => true, 'current_step' => 1]);
    }

    public function test_public_save_for_later_does_not_validate(): void
    {
        $form = $this->twoStepForm();
        $token = $this->initDraft($form);

        $res = $this->putJson("/forms/{$form->slug}/draft/{$token}", [
            'step' => 1,
            'answers' => ['email' => 'partial@example.com'],
        ]);

        $res->assertOk();
        $res->assertJson(['ok' => true]);
    }

    public function test_legacy_single_step_form_submits_unchanged(): void
    {
        // No step rows; the field's form_step_id is NULL — the single-shot
        // /forms/{slug}/submit path, untouched by the per-step gate.
        $form = Form::create([
            'name' => 'Contact',
            'slug' => 'legacy-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'submit_button_text' => 'Submit',
            'webhook_secret' => Str::random(32),
            'created_by' => User::factory()->create()->id,
        ]);
        $form->fields()->create([
            'label' => 'Full name', 'field_key' => 'full_name', 'type' => 'text',
            'is_required' => true, 'width' => 'full', 'sort_order' => 0,
        ]);

        // Whole-form validation still rejects a missing required field...
        $this->postJson("/forms/{$form->slug}/submit", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name']);

        // ...and accepts a complete submission.
        $this->postJson("/forms/{$form->slug}/submit", ['full_name' => 'Dana Scully'])
            ->assertOk();
    }
}
