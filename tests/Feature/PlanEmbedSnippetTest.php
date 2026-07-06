<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Copy embed code" for the Plans widget on the plan-builder page —
 * mirrors the forms Integration panel: the snippet is built SERVER-SIDE
 * (ProductController::buildEmbedSnippet) and shipped as a page prop the
 * copy button reads verbatim, so asserting the prop pins exactly what
 * lands on the visitor's clipboard. Click-to-copy itself is
 * navigator.clipboard — browser API, no Vue test runner in this repo
 * (same posture as the forms panel, which has no frontend test either).
 */
class PlanEmbedSnippetTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_builder_exposes_the_embed_snippet_for_the_products_slug(): void
    {
        $product = Product::create(['slug' => 'comnicube', 'name' => 'ComniCube', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'super_admin']);

        $expected = '<div id="pw-plans-comnicube"></div>'."\n"
            .'<script src="'.rtrim((string) config('app.url'), '/').'/plans/comnicube/embed.js" async></script>';

        $this->actingAs($admin)
            ->get("/settings/products/{$product->id}/plans")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('product.embed_snippet', $expected));
    }

    public function test_snippet_tracks_the_slug_not_a_hardcoded_value(): void
    {
        $product = Product::create(['slug' => 'orderpad-pro', 'name' => 'OrderPad Pro', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)
            ->get("/settings/products/{$product->id}/plans")
            ->assertInertia(fn ($page) => $page
                ->where('product.embed_snippet', fn ($snippet) => str_contains((string) $snippet, 'pw-plans-orderpad-pro')
                    && str_contains((string) $snippet, '/plans/orderpad-pro/embed.js')
                    && str_contains((string) $snippet, 'async')));
    }
}
