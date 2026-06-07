<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormTheme;
use App\Models\User;
use App\Support\FormThemeTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormThemeTokensTest extends TestCase
{
    use RefreshDatabase;

    private function theme(array $tokens): FormTheme
    {
        return FormTheme::create([
            'name' => 'T '.uniqid(),
            'tokens' => $tokens,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function activeForm(?FormTheme $theme = null): Form
    {
        $form = Form::create([
            'name' => 'Contact',
            'slug' => 'contact-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'submit_button_text' => 'Submit',
            'webhook_secret' => Str::random(32),
            'theme_id' => $theme?->id,
            'created_by' => User::factory()->create()->id,
        ]);
        $form->fields()->create([
            'label' => 'Email', 'field_key' => 'email', 'type' => 'email',
            'is_required' => true, 'sort_order' => 1,
        ]);

        return $form;
    }

    public function test_no_theme_resolves_to_the_exact_default_set(): void
    {
        $this->assertSame(FormThemeTokens::defaults(), FormThemeTokens::resolve(null));
    }

    public function test_default_set_preserves_todays_literal_values(): void
    {
        $d = FormThemeTokens::defaults();

        // Spot-check the values that must stay pixel-identical to the old
        // hardcoded widget CSS.
        $this->assertSame('#6366F1', $d['accent']);
        $this->assertSame('#0F172A', $d['button_bg']);
        $this->assertSame('#1f2937', $d['button_bg_hover']);
        $this->assertSame('#ef4444', $d['error']);
        $this->assertSame('#10b981', $d['success_border']);
        $this->assertSame('6px', $d['radius']);
        $this->assertSame('solid', $d['button_style']);
        $this->assertFalse($d['full_width']);
        $this->assertNull($d['logo_url']);
        // Form-container tokens default to NO effect (no padding/border/radius).
        $this->assertSame('0', $d['form_padding']);
        $this->assertSame('0', $d['form_border_width']);
        $this->assertSame('0', $d['form_border_radius']);
        $this->assertSame('#d1d5db', $d['form_border_color']);
    }

    public function test_theme_overrides_apply_and_missing_keys_fall_back(): void
    {
        $theme = $this->theme([
            'accent' => '#0ea5e9',
            'radius' => '12px',
            'full_width' => true,
            'heading' => 'Get in touch',
        ]);

        $resolved = FormThemeTokens::resolve($theme);

        // Overridden:
        $this->assertSame('#0ea5e9', $resolved['accent']);
        $this->assertSame('12px', $resolved['radius']);
        $this->assertTrue($resolved['full_width']);
        $this->assertSame('Get in touch', $resolved['heading']);

        // Fell back to default:
        $this->assertSame('#0F172A', $resolved['button_bg']);
        $this->assertSame('#374151', $resolved['label']);
        $this->assertSame(FormThemeTokens::defaults()['font_family'], $resolved['font_family']);
    }

    public function test_unknown_theme_keys_are_ignored_and_keyset_is_stable(): void
    {
        $theme = $this->theme(['accent' => '#000000', 'totally_unknown' => 'nope']);

        $resolved = FormThemeTokens::resolve($theme);

        $this->assertArrayNotHasKey('totally_unknown', $resolved);
        $this->assertSame(array_keys(FormThemeTokens::defaults()), array_keys($resolved));
        $this->assertSame('#000000', $resolved['accent']);
    }

    public function test_null_token_value_falls_back_to_default(): void
    {
        // A theme that explicitly nulls a key must not blank the widget.
        $resolved = FormThemeTokens::resolve($this->theme(['accent' => null]));

        $this->assertSame('#6366F1', $resolved['accent']);
    }

    public function test_embed_for_unthemed_form_emits_default_tokens(): void
    {
        $form = $this->activeForm();

        $res = $this->get("/forms/{$form->slug}/embed.js");

        $res->assertOk();
        $body = (string) $res->getContent();
        // Default tokens are emitted in the CONFIG.theme JSON and the CSS
        // variables are wired up (the widget joins name+value at runtime).
        $this->assertStringContainsString('"accent":"#6366F1"', $body);   // default accent
        $this->assertStringContainsString('"button_bg":"#0F172A"', $body); // default button bg
        $this->assertStringContainsString('var(--pw-accent)', $body);
        $this->assertStringContainsString('"--pw-accent:"', $body);
    }

    public function test_embed_for_themed_form_emits_overridden_tokens(): void
    {
        $theme = $this->theme(['accent' => '#0ea5e9', 'button_bg' => '#0ea5e9']);
        $form = $this->activeForm($theme);

        $res = $this->get("/forms/{$form->slug}/embed.js");

        $res->assertOk();
        $body = (string) $res->getContent();
        // Overridden tokens flow into CONFIG.theme; defaults still present
        // for the keys the theme didn't touch.
        $this->assertStringContainsString('"accent":"#0ea5e9"', $body);
        $this->assertStringContainsString('"button_bg":"#0ea5e9"', $body);
        $this->assertStringContainsString('"label":"#374151"', $body); // fell back to default
        // Variable wiring still present (rules consume the vars).
        $this->assertStringContainsString('var(--pw-button-bg)', $body);
    }

    public function test_form_container_tokens_emit_pw_form_variables(): void
    {
        $theme = $this->theme([
            'form_padding' => '24px',
            'form_border_width' => '1px',
            'form_border_radius' => '12px',
            'form_border_color' => '#0ea5e9',
        ]);
        $form = $this->activeForm($theme);

        $body = (string) $this->get("/forms/{$form->slug}/embed.js")->assertOk()->getContent();

        // Values flow into CONFIG.theme and the .pw-form rule consumes the vars.
        $this->assertStringContainsString('"form_padding":"24px"', $body);
        $this->assertStringContainsString('"form_border_radius":"12px"', $body);
        $this->assertStringContainsString('"form_border_color":"#0ea5e9"', $body);
        $this->assertStringContainsString('"--pw-form-padding:"', $body);
        $this->assertStringContainsString('var(--pw-form-padding)', $body);
        $this->assertStringContainsString('var(--pw-form-border-width) solid var(--pw-form-border-color)', $body);
        $this->assertStringContainsString('border-radius:var(--pw-form-border-radius)', $body);
    }

    public function test_existing_theme_without_container_keys_renders_no_effect_defaults(): void
    {
        // Backward compat: a theme stored BEFORE these keys existed (its
        // tokens carry none of them) back-fills to the no-effect defaults,
        // so the widget renders an un-styled container exactly as before.
        $theme = $this->theme(['accent' => '#0ea5e9']); // no form_* keys

        $resolved = FormThemeTokens::resolve($theme);
        $this->assertSame('0', $resolved['form_padding']);
        $this->assertSame('0', $resolved['form_border_width']);
        $this->assertSame('0', $resolved['form_border_radius']);
        $this->assertSame('#d1d5db', $resolved['form_border_color']);

        $form = $this->activeForm($theme);
        $body = (string) $this->get("/forms/{$form->slug}/embed.js")->assertOk()->getContent();
        $this->assertStringContainsString('"form_padding":"0"', $body);
        $this->assertStringContainsString('"form_border_width":"0"', $body);
    }
}
