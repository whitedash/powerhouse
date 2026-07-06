<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Single-plan embed (GET /plan/{id}/embed.js) — one card, that plan's
 * prices, none of its siblings. Shares the product embed's Blade IIFE,
 * CORS/cache posture, and checkout endpoint; the only delta is what's
 * rendered and where it mounts (pw-plan-{id}). Plan ids are already
 * public in the widget config, so the numeric URL is not new exposure —
 * and invalid, private, retired, or inactive-product ids all 404
 * identically (one scoped query, the KB/forms public-IDOR stance).
 */
class PlanSingleEmbedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A product with TWO public plans ("Starter", "Pro") so sibling
     * exclusion is observable, plus Pro carrying two active prices
     * (monthly + annual) to pin the all-active-prices decision.
     *
     * @return array{0: Product, 1: ProductPlan, 2: ProductPlan}
     */
    private function catalog(): array
    {
        $product = Product::create(['slug' => 'comnicube', 'name' => 'ComniCube', 'is_active' => true]);

        $starter = ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Starter',
            'is_active' => true, 'is_public' => true,
        ]);
        ProductPlanPrice::create([
            'plan_id' => $starter->id, 'price' => 50,
            'interval_count' => 1, 'interval_unit' => 'one_time', 'is_active' => true,
        ]);

        $pro = ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Pro',
            'is_active' => true, 'is_public' => true,
        ]);
        ProductPlanPrice::create([
            'plan_id' => $pro->id, 'price' => 100, 'label' => 'Monthly',
            'interval_count' => 1, 'interval_unit' => 'month', 'is_active' => true, 'is_default' => true,
        ]);
        ProductPlanPrice::create([
            'plan_id' => $pro->id, 'price' => 1000, 'label' => 'Annual',
            'interval_count' => 1, 'interval_unit' => 'year', 'is_active' => true,
        ]);

        return [$product, $starter, $pro];
    }

    public function test_single_plan_embed_serves_only_that_plan_with_all_its_prices(): void
    {
        [, , $pro] = $this->catalog();

        $response = $this->get("/plan/{$pro->id}/embed.js");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Cache-Control', 'max-age=300, public');

        $body = $response->getContent();
        $this->assertStringContainsString('"Pro"', $body);
        $this->assertStringNotContainsString('Starter', $body);
        // Mounts at its own id, so it can coexist with the full table.
        $this->assertStringContainsString('"root_id":"pw-plan-'.$pro->id.'"', $body);
        // Decision: ALL active prices of the plan render (same card as the
        // full table), not default-only.
        $this->assertStringContainsString('Monthly', $body);
        $this->assertStringContainsString('Annual', $body);
        // Checks out through the SAME product-scoped endpoint — checkout
        // and webhook never learn which embed flavour initiated them.
        $this->assertStringContainsString('/plans/comnicube/checkout', $body);
    }

    public function test_single_plan_embed_404s_identically_for_every_unavailable_state(): void
    {
        [$product, $starter] = $this->catalog();

        $private = ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Hidden',
            'is_active' => true, 'is_public' => false,
        ]);
        $retired = ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Retired',
            'is_active' => false, 'is_public' => true,
        ]);

        $this->get("/plan/{$private->id}/embed.js")->assertNotFound();
        $this->get("/plan/{$retired->id}/embed.js")->assertNotFound();
        $this->get('/plan/999999/embed.js')->assertNotFound();

        // Inactive product takes its public plan down with it.
        $product->update(['is_active' => false]);
        $this->get("/plan/{$starter->id}/embed.js")->assertNotFound();
    }

    public function test_product_embed_still_serves_the_full_lineup(): void
    {
        [$product] = $this->catalog();

        $body = $this->get("/plans/{$product->slug}/embed.js")->assertOk()->getContent();

        $this->assertStringContainsString('Starter', $body);
        $this->assertStringContainsString('"Pro"', $body);
        $this->assertStringContainsString('"root_id":"pw-plans-comnicube"', $body);
    }

    public function test_plan_builder_exposes_per_plan_snippets_only_for_embeddable_plans(): void
    {
        [$product, $starter] = $this->catalog();
        ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Hidden',
            'is_active' => true, 'is_public' => false,
        ]);
        $admin = User::factory()->create(['role' => 'super_admin']);

        $expected = '<div id="pw-plan-'.$starter->id.'"></div>'."\n"
            .'<script src="'.rtrim((string) config('app.url'), '/').'/plan/'.$starter->id.'/embed.js" async></script>';

        $this->actingAs($admin)
            ->get("/settings/products/{$product->id}/plans")
            ->assertInertia(fn ($page) => $page
                ->where('uncategorised.0.embed_snippet', $expected)
                ->where('uncategorised.2.embed_snippet', null));
    }
}
