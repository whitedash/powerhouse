<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 1a: a domain can never (again) be created as a CustomerProduct — it's
 * absent from the enable picker AND blocked server-side.
 */
class EnableProductExcludesDomainTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($this->user);
    }

    private function domainPlanWithPrice(): array
    {
        $product = Product::create(['slug' => 'dom-'.uniqid(), 'name' => 'Domains']);
        $plan = ProductPlan::create([
            'product_id' => $product->id,
            'name' => '.co.uk renewal',
            'is_active' => true,
            'is_public' => true,
            'is_domain' => true,
            'tld' => '.co.uk',
        ]);
        $price = ProductPlanPrice::create([
            'plan_id' => $plan->id,
            'price' => 12.00,
            'interval_count' => 1,
            'interval_unit' => 'year',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return [$product, $plan, $price];
    }

    public function test_enable_product_rejects_an_is_domain_plan(): void
    {
        $customer = Company::create(['name' => 'Acme']);
        [$product, $plan, $price] = $this->domainPlanWithPrice();

        $this->post("/companies/{$customer->id}/products", [
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'status' => 'active',
        ])->assertSessionHasErrors(['plan_id']);

        $this->assertSame(0, CustomerProduct::count());
    }

    public function test_available_products_picker_excludes_is_domain_plans(): void
    {
        $customer = Company::create(['name' => 'Acme']);
        [, $plan] = $this->domainPlanWithPrice();

        $this->get("/companies/{$customer->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Internal/Customers/Show')
                ->where('available_products', fn ($products) => collect($products)
                    ->flatMap(fn ($p) => $p['plans'])
                    ->pluck('id')
                    ->doesntContain($plan->id))
            );
    }
}
