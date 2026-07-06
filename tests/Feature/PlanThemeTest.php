<?php

namespace Tests\Feature;

use App\Models\FormTheme;
use App\Models\PlanTheme;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use App\Services\StripeService;
use App\Support\PlanThemeTokens;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan themes — FormThemeCrudTest + FormThemeTokensTest mirrored, plus
 * the Stripe branding bridge (one token set styles the widget AND the
 * payment step; border_style enum pill|rectangular|rounded verified
 * against the live API reference at build time).
 */
class PlanThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function staff(): User
    {
        $role = Role::create(['name' => 'pt_'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo(['products.manage']);
        $user = User::factory()->create();
        $user->syncRoles([$role->name]);

        return $user->fresh();
    }

    // ── tokens resolver (FormThemeTokensTest mirror) ─────────────────────

    public function test_no_theme_resolves_to_the_exact_defaults(): void
    {
        $this->assertSame(PlanThemeTokens::defaults(), PlanThemeTokens::resolve(null));
    }

    public function test_overrides_merge_and_unknown_or_null_keys_fall_back(): void
    {
        $theme = new PlanTheme(['tokens' => [
            'card_bg' => '#111827',
            'button_bg' => null,          // null → default
            'bogus_key' => '#ff0000',     // unknown → ignored
        ]]);

        $resolved = PlanThemeTokens::resolve($theme);

        $this->assertSame('#111827', $resolved['card_bg']);
        $this->assertSame('#0f172a', $resolved['button_bg']);
        $this->assertArrayNotHasKey('bogus_key', $resolved);
    }

    // ── Stripe branding bridge ───────────────────────────────────────────

    public function test_branding_bridge_maps_hex_tokens_and_buckets_radius(): void
    {
        $branding = PlanThemeTokens::toStripeBranding(PlanThemeTokens::resolve(new PlanTheme(['tokens' => [
            'background' => '#f8fafc',
            'button_bg' => '#7c3aed',
            'radius' => '20px',
            'logo_url' => 'https://cdn.example.com/logo.png',
        ]])));

        $this->assertSame('#f8fafc', $branding['background_color']);
        $this->assertSame('#7c3aed', $branding['button_color']);
        $this->assertSame('pill', $branding['border_style']);
        $this->assertSame(['type' => 'url', 'url' => 'https://cdn.example.com/logo.png'], $branding['logo']);
    }

    public function test_branding_bridge_omits_non_hex_colours_gracefully(): void
    {
        $branding = PlanThemeTokens::toStripeBranding(PlanThemeTokens::resolve(new PlanTheme(['tokens' => [
            'background' => 'rgba(0,0,0,.5)', // not hex → omitted
            'radius' => '2px',
        ]])));

        $this->assertArrayNotHasKey('background_color', $branding);
        $this->assertSame('rectangular', $branding['border_style']);
    }

    public function test_checkout_session_params_carry_the_products_theme_branding(): void
    {
        $theme = PlanTheme::create(['name' => 'Violet', 'tokens' => ['button_bg' => '#7c3aed'], 'created_by' => $this->admin()->id]);
        $product = Product::create(['slug' => 'comnicube', 'name' => 'ComniCube', 'is_active' => true, 'theme_id' => $theme->id]);
        $plan = ProductPlan::create(['product_id' => $product->id, 'name' => 'Starter', 'is_active' => true, 'is_public' => true]);
        $price = ProductPlanPrice::create(['plan_id' => $plan->id, 'price' => 100, 'interval_count' => 1, 'interval_unit' => 'one_time', 'is_active' => true]);

        $params = app(StripeService::class)->planCheckoutSessionParams(
            $price, 'Pat', 'pat@gmail.com', 120.0, 'comnicube',
        );

        $this->assertSame('#7c3aed', $params['branding_settings']['button_color']);
        $this->assertSame('rounded', $params['branding_settings']['border_style']);
    }

    // ── CRUD + custom_css gate (FormThemeCrudTest mirror) ────────────────

    public function test_custom_css_is_ignored_for_non_super_admin(): void
    {
        $this->actingAs($this->staff())
            ->post('/settings/plan-themes', [
                'name' => 'Staff theme',
                'tokens' => ['accent' => '#111111', 'custom_css' => '.pw-plan{display:none}'],
            ])->assertRedirect();

        $this->assertArrayNotHasKey('custom_css', PlanTheme::sole()->tokens);
    }

    public function test_forms_custom_css_permission_does_not_grant_plan_theme_css(): void
    {
        // The two embed surfaces are granted independently: holding the
        // FORMS raw-CSS permission buys nothing here.
        $role = Role::create(['name' => 'fcss_'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo(['products.manage', 'forms.custom_css']);
        $user = User::factory()->create();
        $user->syncRoles([$role->name]);

        $this->actingAs($user->fresh())
            ->post('/settings/plan-themes', [
                'name' => 'Cross-perm theme',
                'tokens' => ['custom_css' => '.pw-plan{display:none}'],
            ])->assertRedirect();

        $this->assertArrayNotHasKey('custom_css', PlanTheme::sole()->tokens);
    }

    public function test_products_custom_css_permission_does_not_grant_form_theme_css(): void
    {
        // …and vice versa: the PLANS raw-CSS permission doesn't open forms.
        $role = Role::create(['name' => 'pcss_'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo(['forms.access', 'forms.manage', 'products.custom_css']);
        $user = User::factory()->create();
        $user->syncRoles([$role->name]);

        $this->actingAs($user->fresh())
            ->post('/forms/themes', [
                'name' => 'Cross-perm form theme',
                'tokens' => ['custom_css' => '.pw-form{display:none}'],
            ]);

        $formTheme = FormTheme::first();
        if ($formTheme !== null) {
            $this->assertArrayNotHasKey('custom_css', $formTheme->tokens ?? []);
        }
        $this->assertFalse($user->fresh()->can('manageCustomCss', FormTheme::class));
    }

    public function test_products_custom_css_holder_can_inject_plan_theme_css(): void
    {
        $role = Role::create(['name' => 'pc_'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo(['products.manage', 'products.custom_css']);
        $user = User::factory()->create();
        $user->syncRoles([$role->name]);

        $this->actingAs($user->fresh())
            ->post('/settings/plan-themes', [
                'name' => 'Plans CSS theme',
                'tokens' => ['custom_css' => '.pw-plan{outline:none}'],
            ])->assertRedirect();

        $this->assertSame('.pw-plan{outline:none}', PlanTheme::sole()->tokens['custom_css']);
    }

    public function test_custom_css_is_persisted_for_super_admin(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/plan-themes', [
                'name' => 'Admin theme',
                'tokens' => ['accent' => '#111111', 'custom_css' => '.pw-plan{letter-spacing:.02em}'],
            ])->assertRedirect();

        $this->assertSame('.pw-plan{letter-spacing:.02em}', PlanTheme::sole()->tokens['custom_css']);
    }

    public function test_staff_edit_preserves_existing_super_admin_custom_css(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/settings/plan-themes', [
            'name' => 'Shared', 'tokens' => ['custom_css' => '.pw-plan{opacity:.99}'],
        ]);
        $theme = PlanTheme::sole();

        $this->actingAs($this->staff())->put("/settings/plan-themes/{$theme->id}", [
            'name' => 'Shared (renamed)', 'tokens' => ['accent' => '#222222'],
        ])->assertRedirect();

        $theme->refresh();
        $this->assertSame('Shared (renamed)', $theme->name);
        $this->assertSame('.pw-plan{opacity:.99}', $theme->tokens['custom_css']);
        $this->assertSame('#222222', $theme->tokens['accent']);
    }

    public function test_deleting_a_theme_reverts_products_to_default(): void
    {
        $admin = $this->admin();
        $theme = PlanTheme::create(['name' => 'Doomed', 'tokens' => [], 'created_by' => $admin->id]);
        $product = Product::create(['slug' => 'x', 'name' => 'X', 'is_active' => true, 'theme_id' => $theme->id]);

        $this->actingAs($admin)->delete("/settings/plan-themes/{$theme->id}")->assertRedirect();

        $this->assertNull($product->fresh()->theme_id);
    }
}
