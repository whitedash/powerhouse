<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Requires manual review" toggle on the Edit Plan slide-over — the
 * fifth Visibility toggle, wired to product_plans.requires_manual_review
 * through the same validatePayload() path as the other visibility flags
 * (Plans-widget review gate: a true plan holds self-serve purchases at
 * customer_products status='pending' until staff confirm).
 */
class PlanManualReviewToggleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Product, 1: User} */
    private function setUpProduct(): array
    {
        $product = Product::create(['slug' => 'comnicube', 'name' => 'ComniCube', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'super_admin']);

        return [$product, $admin];
    }

    public function test_new_plans_default_to_not_requiring_review(): void
    {
        [$product, $admin] = $this->setUpProduct();

        // The create form always sends the field (defaults false), but the
        // column default must also hold when it's omitted entirely.
        $this->actingAs($admin)->post('/settings/plans', [
            'product_id' => $product->id,
            'name' => 'Starter',
        ])->assertRedirect();

        $this->assertDatabaseHas('product_plans', [
            'name' => 'Starter',
            'requires_manual_review' => false,
        ]);
    }

    public function test_toggle_persists_on_create_and_update(): void
    {
        [$product, $admin] = $this->setUpProduct();

        $this->actingAs($admin)->post('/settings/plans', [
            'product_id' => $product->id,
            'name' => 'Enterprise',
            'requires_manual_review' => true,
        ])->assertRedirect();

        $plan = ProductPlan::where('name', 'Enterprise')->firstOrFail();
        $this->assertTrue($plan->requires_manual_review);

        // Toggle back off through the same update path the modal uses.
        $this->actingAs($admin)->put("/settings/plans/{$plan->id}", [
            'name' => 'Enterprise',
            'requires_manual_review' => false,
        ])->assertRedirect();

        $this->assertFalse($plan->fresh()->requires_manual_review);
    }

    public function test_edit_modal_reads_the_current_value_from_the_builder_props(): void
    {
        [$product, $admin] = $this->setUpProduct();
        ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Held plan',
            'is_active' => true, 'is_public' => true, 'requires_manual_review' => true,
        ]);
        ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Live plan',
            'is_active' => true, 'is_public' => true,
        ]);

        // openEditPlan() hydrates the form from these props, so this is
        // what the toggle renders. Pre-existing rows (migration default)
        // read false — the "all currently show off" expectation.
        $this->actingAs($admin)
            ->get("/settings/products/{$product->id}/plans")
            ->assertInertia(fn ($page) => $page
                ->where('uncategorised.0.requires_manual_review', true)
                ->where('uncategorised.1.requires_manual_review', false));
    }
}
