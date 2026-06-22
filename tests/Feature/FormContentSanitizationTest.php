<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Form "placeholder" (text-block) content holds HTML that is rendered raw — via
 * v-html in FormFieldRenderer and, critically, via innerHTML in the cross-origin
 * embed widget. App\Services\FormContentSanitizer allow-list sanitises it
 * server-side on SAVE (FormBuilderController) and on SERVE (EmbedController), so
 * both new saves and already-stored (raw, legacy) rows are safe.
 */
class FormContentSanitizationTest extends TestCase
{
    use RefreshDatabase;

    /** POST a single-placeholder form through the builder; return the saved field. */
    private function savePlaceholder(string $content): FormField
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->post('/forms', [
                'name' => 'Sanitise',
                'slug' => 'san-'.Str::lower(Str::random(6)),
                'status' => 'active',
                'steps' => [[
                    'label' => 'Step 1',
                    'sort_order' => 0,
                    'fields' => [[
                        'label' => '', 'field_key' => '', 'type' => 'placeholder',
                        'is_required' => false, 'width' => 'full', 'sort_order' => 0,
                        'content' => $content,
                    ]],
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return FormField::where('type', 'placeholder')->firstOrFail();
    }

    /** Build an active form whose placeholder field holds RAW content (legacy row); return its slug. */
    private function rawEmbedForm(string $content): string
    {
        $form = Form::create([
            'name' => 'Embed',
            'slug' => 'embed-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'submit_button_text' => 'Submit',
            'webhook_secret' => Str::random(32),
            'created_by' => User::factory()->create()->id,
        ]);
        // Persist RAW (no sanitiser) to simulate a row saved before the fix.
        $form->fields()->create([
            'label' => '', 'field_key' => 'placeholder_0', 'type' => 'placeholder',
            'content' => $content, 'is_required' => false, 'width' => 'full', 'sort_order' => 0,
        ]);

        return $form->slug;
    }

    /** Decode the placeholder content the embed widget actually serves. */
    private function embedServedContent(string $slug): string
    {
        $body = $this->get("/forms/{$slug}/embed.js")->assertOk()->getContent();

        // The widget embeds its config as `var CONFIG = {json};` on one line.
        $json = Str::before(Str::after($body, 'var CONFIG = '), ";\n");
        $config = json_decode($json, true);
        $this->assertIsArray($config, 'embed CONFIG JSON should decode');

        return collect($config['fields'])->firstWhere('type', 'placeholder')['content'] ?? '';
    }

    public function test_malicious_placeholder_content_is_sanitised_on_save(): void
    {
        $field = $this->savePlaceholder('<p>Hello</p><script>alert("xss")</script><img src=x onerror="alert(1)">');

        $this->assertStringNotContainsString('<script', $field->content);
        $this->assertStringNotContainsString('onerror', $field->content);
        $this->assertStringContainsString('<p>Hello</p>', $field->content);
    }

    public function test_legitimate_placeholder_formatting_survives_save(): void
    {
        $field = $this->savePlaceholder('<p><strong>Bold</strong> see <a href="https://example.com">link</a></p>');

        $this->assertStringContainsString('<strong>Bold</strong>', $field->content);
        $this->assertStringContainsString('href="https://example.com"', $field->content);
    }

    public function test_embed_serves_sanitised_content_for_a_raw_legacy_row(): void
    {
        $slug = $this->rawEmbedForm('<p>Hi</p><script>alert("xss")</script><img src=x onerror="alert(1)">');

        $content = $this->embedServedContent($slug);

        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('onerror', $content);
        $this->assertStringContainsString('<p>Hi</p>', $content);
    }

    public function test_embed_preserves_legitimate_formatting(): void
    {
        $slug = $this->rawEmbedForm('<p><strong>Bold</strong> <a href="https://example.com">link</a></p>');

        $content = $this->embedServedContent($slug);

        $this->assertStringContainsString('<strong>Bold</strong>', $content);
        $this->assertStringContainsString('href="https://example.com"', $content);
    }
}
