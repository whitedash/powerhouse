<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormTheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormThemeCrudTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function theme(array $tokens, ?int $creator = null): FormTheme
    {
        return FormTheme::create([
            'name' => 'T '.uniqid(),
            'tokens' => $tokens,
            'created_by' => $creator ?? $this->staff()->id,
        ]);
    }

    public function test_staff_can_create_a_theme_persisting_known_tokens_and_logging(): void
    {
        $this->actingAs($this->staff())
            ->post('/forms/themes', [
                'name' => 'Ocean',
                'tokens' => ['accent' => '#0ea5e9', 'radius' => '12px', 'full_width' => true, 'bogus' => 'x'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $theme = FormTheme::sole();
        $this->assertSame('Ocean', $theme->name);
        $this->assertSame('#0ea5e9', $theme->tokens['accent']);
        $this->assertSame('12px', $theme->tokens['radius']);
        $this->assertTrue($theme->tokens['full_width']);
        // Unknown keys dropped.
        $this->assertArrayNotHasKey('bogus', $theme->tokens);

        $this->assertDatabaseHas('activity_log', [
            'action' => 'form_theme.created',
            'entity_type' => 'form_theme',
            'entity_id' => $theme->id,
        ]);
    }

    public function test_button_hover_persists_and_validates(): void
    {
        $this->actingAs($this->staff())
            ->post('/forms/themes', ['name' => 'Glowy', 'tokens' => ['button_hover' => 'glow']])
            ->assertSessionHasNoErrors();
        $this->assertSame('glow', FormTheme::sole()->tokens['button_hover']);

        // Invalid hover mode is rejected.
        $this->actingAs($this->staff())
            ->post('/forms/themes', ['name' => 'Bad', 'tokens' => ['button_hover' => 'sparkle']])
            ->assertSessionHasErrors('tokens.button_hover');
    }

    public function test_custom_css_is_ignored_for_non_super_admin(): void
    {
        $this->actingAs($this->staff())
            ->post('/forms/themes', [
                'name' => 'NoCss',
                'tokens' => ['accent' => '#111111', 'custom_css' => '.pw-form{display:none}'],
            ])
            ->assertSessionHasNoErrors();

        $theme = FormTheme::sole();
        $this->assertArrayNotHasKey('custom_css', $theme->tokens);
    }

    public function test_custom_css_is_persisted_for_super_admin(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/forms/themes', [
                'name' => 'WithCss',
                'tokens' => ['accent' => '#111111', 'custom_css' => '.pw-form button{letter-spacing:.02em}'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('.pw-form button{letter-spacing:.02em}', FormTheme::sole()->tokens['custom_css']);
    }

    public function test_staff_edit_preserves_existing_super_admin_custom_css(): void
    {
        $theme = $this->theme(['accent' => '#111111', 'custom_css' => '.pw-form{outline:1px solid red}']);

        $this->actingAs($this->staff())
            ->put("/forms/themes/{$theme->id}", [
                'name' => 'Renamed',
                'tokens' => ['accent' => '#222222'], // staff omits/can't send custom_css
            ])
            ->assertSessionHasNoErrors();

        $fresh = $theme->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('#222222', $fresh->tokens['accent']);
        // The super-admin's CSS survives a staff edit.
        $this->assertSame('.pw-form{outline:1px solid red}', $fresh->tokens['custom_css']);
    }

    public function test_index_lists_themes_and_paginates_20(): void
    {
        $staff = $this->staff();
        foreach (range(1, 21) as $i) {
            FormTheme::create(['name' => "Theme {$i}", 'tokens' => ['accent' => '#000000'], 'created_by' => $staff->id]);
        }

        $res = $this->actingAs($staff)->get('/forms/themes');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Internal/Forms/Themes/Index')
            ->where('themes.per_page', 20)
            ->where('themes.total', 21)
            ->has('themes.data', 20)
            ->where('can.manage_custom_css', false)
        );
    }

    public function test_deleting_a_theme_reverts_forms_to_default(): void
    {
        $staff = $this->staff();
        $theme = $this->theme(['accent' => '#000000'], $staff->id);
        $form = Form::create([
            'name' => 'Contact', 'slug' => 'contact-'.Str::lower(Str::random(6)),
            'status' => 'active', 'submit_button_text' => 'Submit',
            'webhook_secret' => Str::random(32), 'theme_id' => $theme->id, 'created_by' => $staff->id,
        ]);

        $this->actingAs($staff)
            ->delete("/forms/themes/{$theme->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('form_themes', ['id' => $theme->id]);
        $this->assertNull($form->fresh()->theme_id); // nullOnDelete
    }

    public function test_form_can_be_assigned_a_theme_in_the_builder(): void
    {
        $staff = $this->staff();
        $theme = $this->theme(['accent' => '#000000'], $staff->id);

        $this->actingAs($staff)
            ->post('/forms', [
                'name' => 'Lead form',
                'slug' => 'lead-'.Str::lower(Str::random(6)),
                'theme_id' => $theme->id,
                'fields' => [
                    ['label' => 'Email', 'field_key' => 'email', 'type' => 'email', 'is_required' => true],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($theme->id, Form::sole()->theme_id);
    }
}
