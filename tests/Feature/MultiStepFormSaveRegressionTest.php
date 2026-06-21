<?php

namespace Tests\Feature;

use App\Models\FormField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression guard for the multi-step builder save.
 *
 * A `steps.*.fields.*.content` rule once lived in FormBuilderController::validatePayload.
 * Being the only validated CHILD rule under `steps.*.fields.*`, it switched on Laravel's
 * `excludeUnvalidatedArrayKeys`, which prunes every UN-validated sibling key (type, label,
 * field_key, …) from validated(). The cross-validation pass then read null type/label/key
 * and emitted false "field is missing a label / invalid key / invalid field type" errors on
 * EVERY field — including plain text/email — and would have persisted gutted rows.
 *
 * This test posts a representative multi-step form (text, email, datetime, and a display-only
 * placeholder/rich-text field) and asserts the save succeeds with full data intact. It FAILS
 * the moment any child rule under `steps.*.fields.*` is reintroduced, because the prune would
 * resurface the false validation errors (assertSessionHasNoErrors) and gut the persisted
 * type/label/key/content (the per-field assertions below).
 */
class MultiStepFormSaveRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    public function test_multi_step_save_persists_every_field_type_without_pruning_validated_keys(): void
    {
        $this->actingAs($this->staff())
            ->post('/forms', [
                'name' => 'Mixed field types',
                'slug' => 'mixed-'.Str::lower(Str::random(6)),
                'status' => 'active',
                'steps' => [[
                    'label' => 'asdfafs',
                    'sort_order' => 0,
                    'fields' => [
                        ['label' => 'First name', 'field_key' => 'first_name', 'type' => 'text', 'is_required' => true, 'width' => 'full', 'sort_order' => 0, 'content' => null],
                        ['label' => 'Email', 'field_key' => 'email', 'type' => 'email', 'is_required' => true, 'width' => 'full', 'sort_order' => 1, 'content' => null],
                        ['label' => 'Appointment', 'field_key' => 'appointment', 'type' => 'datetime', 'is_required' => true, 'width' => 'full', 'sort_order' => 2, 'content' => null],
                        // Display-only rich-text block: blank label + no key (synthesised
                        // server-side), its text carried in `content`.
                        ['label' => '', 'field_key' => '', 'type' => 'placeholder', 'is_required' => false, 'width' => 'full', 'sort_order' => 3, 'content' => '<p>Please read carefully.</p>'],
                    ],
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fields = FormField::orderBy('sort_order')->get();
        $this->assertCount(4, $fields);

        $this->assertSame('text', $fields[0]->type);
        $this->assertSame('First name', $fields[0]->label);
        $this->assertSame('first_name', $fields[0]->field_key);

        $this->assertSame('email', $fields[1]->type);
        $this->assertSame('Email', $fields[1]->label);
        $this->assertSame('email', $fields[1]->field_key);

        $this->assertSame('datetime', $fields[2]->type);
        $this->assertSame('Appointment', $fields[2]->label);
        $this->assertSame('appointment', $fields[2]->field_key);

        // Placeholder: type/key preserved, label emptied server-side, rich-text body stored.
        $this->assertSame('placeholder', $fields[3]->type);
        $this->assertSame('placeholder_3', $fields[3]->field_key);
        $this->assertSame('', $fields[3]->label);
        $this->assertSame('<p>Please read carefully.</p>', $fields[3]->content);
    }
}
