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

    public function test_branding_bridge_maps_font_stacks_onto_stripes_named_font_enum(): void
    {
        // branding_settings.font_family is an ENUM, not a CSS string —
        // sending the raw stack 400s the session (the v4-blocking bug).
        $font = fn (string $stack): array => PlanThemeTokens::toStripeBranding(
            PlanThemeTokens::resolve(new PlanTheme(['tokens' => ['font_family' => $stack]])),
        );

        // Exact match, first family wins.
        $this->assertSame('inter', $font('Inter, sans-serif')['font_family']);
        // Multi-word names normalise onto the enum keys.
        $this->assertSame('open_sans', $font("'Open Sans', Arial, sans-serif")['font_family']);
        // The widget's DEFAULT system stack maps via its Roboto fallback —
        // an unthemed product now brands the payment step as roboto
        // instead of 500ing the purchase.
        $this->assertSame('roboto', PlanThemeTokens::toStripeBranding(PlanThemeTokens::resolve(null))['font_family']);
    }

    public function test_branding_bridge_omits_unrecognised_fonts_instead_of_failing(): void
    {
        $branding = PlanThemeTokens::toStripeBranding(PlanThemeTokens::resolve(new PlanTheme(['tokens' => [
            'font_family' => "'Comic Sans MS', cursive",
        ]])));

        // No clean enum match → OMIT (never send garbage): Stripe falls
        // back to the account's dashboard branding.
        $this->assertArrayNotHasKey('font_family', $branding);
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

    // ── editor UI page + theme picker (chunk 3) ──────────────────────────

    public function test_editor_page_gates_the_custom_css_flag_by_permission(): void
    {
        PlanTheme::create(['name' => 'Existing', 'tokens' => ['custom_css' => '.pw-plan{}'], 'created_by' => $this->admin()->id]);

        // super_admin: flag true, custom_css visible in the listed tokens.
        $this->actingAs($this->admin())
            ->get('/settings/plan-themes')
            ->assertInertia(fn ($page) => $page
                ->component('Internal/Plans/Themes/Index')
                ->where('can.manage_custom_css', true)
                ->where('themes.data.0.tokens.custom_css', '.pw-plan{}')
                ->has('default_tokens.card_bg'));

        // products.manage without products.custom_css: flag false and the
        // stored CSS is STRIPPED from the payload (read-gate, not just UI).
        $this->actingAs($this->staff())
            ->get('/settings/plan-themes')
            ->assertInertia(fn ($page) => $page
                ->where('can.manage_custom_css', false)
                ->missing('themes.data.0.tokens.custom_css')
                ->missing('default_tokens.custom_css'));
    }

    public function test_product_theme_picker_assigns_and_clears_theme_id(): void
    {
        $admin = $this->admin();
        $theme = PlanTheme::create(['name' => 'Ocean', 'tokens' => [], 'created_by' => $admin->id]);
        $product = Product::create(['slug' => 'comnicube', 'name' => 'ComniCube', 'is_active' => true, 'icon_colour' => '#0D9488']);

        $payload = [
            'name' => 'ComniCube', 'slug' => 'comnicube', 'icon_colour' => '#0D9488',
        ];

        $this->actingAs($admin)
            ->put("/settings/products/{$product->id}", $payload + ['theme_id' => $theme->id])
            ->assertRedirect();
        $this->assertSame($theme->id, $product->fresh()->theme_id);

        // Picker sends null = revert to the default look.
        $this->actingAs($admin)
            ->put("/settings/products/{$product->id}", $payload + ['theme_id' => null])
            ->assertRedirect();
        $this->assertNull($product->fresh()->theme_id);

        // The Products settings page feeds the picker.
        $this->actingAs($admin)
            ->get('/settings/products')
            ->assertInertia(fn ($page) => $page
                ->where('plan_themes.0.name', 'Ocean')
                ->where('products.0.theme_id', null));
    }

    // ── shadow-DOM embed rendering ───────────────────────────────────────

    public function test_embed_renders_shadow_dom_with_themed_variables_and_custom_css(): void
    {
        $theme = PlanTheme::create([
            'name' => 'Dark',
            'tokens' => ['card_bg' => '#111827', 'custom_css' => '.pw-plan{letter-spacing:.01em}'],
            'created_by' => $this->admin()->id,
        ]);
        $product = Product::create(['slug' => 'comnicube', 'name' => 'ComniCube', 'is_active' => true, 'theme_id' => $theme->id]);
        $plan = ProductPlan::create(['product_id' => $product->id, 'name' => 'Starter', 'is_active' => true, 'is_public' => true]);
        ProductPlanPrice::create(['plan_id' => $plan->id, 'price' => 100, 'interval_count' => 1, 'interval_unit' => 'one_time', 'is_active' => true]);

        $body = $this->get('/plans/comnicube/embed.js')->assertOk()->getContent();

        // Shadow-root isolation (forms' idiom) + the theme override + the
        // super_admin custom_css riding in the config, + the modal
        // lifecycle guards (destroy-on-close; dialog semantics). The
        // open/close/reopen behaviour itself is browser JS — no JS test
        // runner exists in this repo (same finding as every widget
        // change), so this pins the shipped code paths statically.
        $this->assertStringContainsString('attachShadow', $body);
        $this->assertStringContainsString('"card_bg":"#111827"', $body);
        $this->assertStringContainsString('.pw-plan{letter-spacing:.01em}', $body);
        $this->assertStringContainsString('checkoutInstance.destroy()', $body);
        $this->assertStringContainsString('role: "dialog"', $body);
    }

    public function test_unthemed_embed_ships_the_default_tokens(): void
    {
        $product = Product::create(['slug' => 'bare', 'name' => 'Bare', 'is_active' => true]);
        $plan = ProductPlan::create(['product_id' => $product->id, 'name' => 'Solo', 'is_active' => true, 'is_public' => true]);
        ProductPlanPrice::create(['plan_id' => $plan->id, 'price' => 10, 'interval_count' => 1, 'interval_unit' => 'one_time', 'is_active' => true]);

        $body = $this->get('/plans/bare/embed.js')->assertOk()->getContent();

        // Pixel-identical guarantee: defaults ship verbatim.
        $this->assertStringContainsString('"card_bg":"#ffffff"', $body);
        $this->assertStringContainsString('"button_bg":"#0f172a"', $body);
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
