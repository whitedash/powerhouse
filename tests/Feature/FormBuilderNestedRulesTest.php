<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hardening for the excludeUnvalidatedArrayKeys footgun on the form builder.
 *
 * steps.*.fields.* now rules every persisted field key explicitly, so validated()
 * returns the COMPLETE field (no silent sibling prune) AND the field-type allowlist
 * fires as a clean 422 at the validation layer instead of letting an unknown type
 * reach the DB enum and throw a 500.
 */
class FormBuilderNestedRulesTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function payload(array $fields, string $slug): array
    {
        return [
            'name' => 'Form', 'slug' => $slug, 'status' => 'active',
            'steps' => [['label' => 'Step 1', 'sort_order' => 0, 'fields' => $fields]],
        ];
    }

    public function test_invalid_field_type_is_a_clean_422_not_a_500(): void
    {
        $resp = $this->actingAs($this->staff())->post('/forms', $this->payload([
            ['label' => 'Name', 'field_key' => 'name', 'type' => 'text', 'is_required' => true, 'width' => 'full', 'sort_order' => 0],
            ['label' => 'Bad', 'field_key' => 'bad', 'type' => 'totally_not_a_type', 'is_required' => false, 'width' => 'full', 'sort_order' => 1],
        ], 'bad-'.Str::lower(Str::random(6))));

        // A validation failure surfaces as a redirect-back-with-errors (302) on a
        // web/Inertia POST — NOT a 500. The error is keyed by the in: allowlist rule.
        $resp->assertStatus(302)->assertSessionHasErrors(['steps.0.fields.1.type']);
        $this->assertSame(0, Form::count());
        $this->assertSame(0, FormField::count());
    }

    public function test_placeholder_content_and_all_siblings_round_trip(): void
    {
        $this->actingAs($this->staff())->post('/forms', $this->payload([
            ['label' => 'Name', 'field_key' => 'name', 'type' => 'text', 'is_required' => true, 'width' => 'full', 'sort_order' => 0, 'content' => null],
            ['label' => '', 'field_key' => '', 'type' => 'placeholder', 'is_required' => false, 'width' => 'full', 'sort_order' => 1, 'content' => '<p>Read <strong>me</strong></p>'],
        ], 'ok-'.Str::lower(Str::random(6))))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fields = FormField::orderBy('sort_order')->get();
        $this->assertCount(2, $fields);

        // The ruled text field's siblings survive (proves no prune now that every
        // key is ruled — a partial child rule would have gutted these).
        $this->assertSame('text', $fields[0]->type);
        $this->assertSame('Name', $fields[0]->label);
        $this->assertSame('name', $fields[0]->field_key);

        // The re-added content rule persists, with type/key intact.
        $this->assertSame('placeholder', $fields[1]->type);
        $this->assertSame('placeholder_1', $fields[1]->field_key);
        $this->assertSame('<p>Read <strong>me</strong></p>', $fields[1]->content);
    }
}
