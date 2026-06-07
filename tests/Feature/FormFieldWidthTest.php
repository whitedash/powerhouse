<?php

namespace Tests\Feature;

use App\Enums\FieldWidth;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormFieldWidthTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    private function activeForm(array $fieldWidths): Form
    {
        $form = Form::create([
            'name' => 'Contact',
            'slug' => 'contact-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'submit_button_text' => 'Submit',
            'webhook_secret' => Str::random(32),
            'created_by' => $this->staff()->id,
        ]);
        foreach ($fieldWidths as $i => $w) {
            $form->fields()->create([
                'label' => 'F'.$i, 'field_key' => 'f'.$i, 'type' => 'text',
                'is_required' => false, 'width' => $w, 'sort_order' => $i,
            ]);
        }

        return $form;
    }

    public function test_width_enum_maps_to_grid_columns(): void
    {
        $this->assertSame(12, FieldWidth::Full->cols());
        $this->assertSame(6, FieldWidth::Half->cols());
        $this->assertSame(4, FieldWidth::Third->cols());
    }

    public function test_builder_persists_field_width(): void
    {
        $this->actingAs($this->staff())
            ->post('/forms', [
                'name' => 'Multi',
                'slug' => 'multi-'.Str::lower(Str::random(6)),
                'fields' => [
                    ['label' => 'First', 'field_key' => 'first', 'type' => 'text', 'width' => 'half'],
                    ['label' => 'Last', 'field_key' => 'last', 'type' => 'text', 'width' => 'half'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $fields = FormField::orderBy('sort_order')->get();
        $this->assertSame(FieldWidth::Half, $fields[0]->width);
        $this->assertSame(FieldWidth::Half, $fields[1]->width);
    }

    public function test_width_defaults_to_full_when_omitted(): void
    {
        $this->actingAs($this->staff())
            ->post('/forms', [
                'name' => 'NoWidth',
                'slug' => 'nowidth-'.Str::lower(Str::random(6)),
                'fields' => [
                    ['label' => 'Email', 'field_key' => 'email', 'type' => 'email'], // no width
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(FieldWidth::Full, FormField::sole()->width);
    }

    public function test_invalid_width_is_rejected(): void
    {
        $this->actingAs($this->staff())
            ->post('/forms', [
                'name' => 'Bad',
                'slug' => 'bad-'.Str::lower(Str::random(6)),
                'fields' => [
                    ['label' => 'Email', 'field_key' => 'email', 'type' => 'email', 'width' => 'quarter'],
                ],
            ])
            ->assertSessionHasErrors('fields.0.width');

        $this->assertSame(0, FormField::count());
    }

    public function test_widget_emits_correct_grid_spans(): void
    {
        $form = $this->activeForm(['full', 'half', 'third']);

        $body = (string) $this->get("/forms/{$form->slug}/embed.js")->assertOk()->getContent();

        // The widget JS carries each field's width + the span class map.
        $this->assertStringContainsString('"width":"full"', $body);
        $this->assertStringContainsString('"width":"half"', $body);
        $this->assertStringContainsString('"width":"third"', $body);
        $this->assertStringContainsString('pw-col-12', $body);
        $this->assertStringContainsString('pw-col-6', $body);
        $this->assertStringContainsString('pw-col-4', $body);
        // Grid container + container-query collapse present.
        $this->assertStringContainsString('pw-grid', $body);
        $this->assertStringContainsString('container-type:inline-size', $body);
        $this->assertStringContainsString('@container', $body);
    }

    public function test_existing_full_only_form_is_single_column(): void
    {
        // Backward compat: a form whose fields are all full (the default)
        // emits only full-width spans — i.e. today's single-column stack.
        $form = $this->activeForm(['full', 'full']);

        $body = (string) $this->get("/forms/{$form->slug}/embed.js")->assertOk()->getContent();

        $this->assertStringContainsString('pw-col-12', $body);
        $this->assertStringNotContainsString('"width":"half"', $body);
        $this->assertStringNotContainsString('"width":"third"', $body);
    }
}
