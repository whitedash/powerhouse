<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 1a: hosting is a property of the website, picked from the catalog with
 * NO pre-enabled CustomerProduct (the decoupling).
 */
class WebsiteHostingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($this->user);
    }

    private function customer(): Company
    {
        return Company::create(['name' => 'Acme '.uniqid()]);
    }

    private function hostingPlan(bool $isDomain = false): ProductPlan
    {
        $product = Product::create(['slug' => 'host-'.uniqid(), 'name' => 'Hosting']);

        return ProductPlan::create([
            'product_id' => $product->id,
            'name' => 'Pro hosting',
            'is_active' => true,
            'is_public' => true,
            'is_hosting' => ! $isDomain,
            'is_domain' => $isDomain,
        ]);
    }

    private function price(ProductPlan $plan): ProductPlanPrice
    {
        return ProductPlanPrice::create([
            'plan_id' => $plan->id,
            'price' => 25.00,
            'interval_count' => 1,
            'interval_unit' => 'month',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_website_attaches_a_catalog_hosting_plan_with_no_pre_enabled_customer_product(): void
    {
        $customer = $this->customer();
        $plan = $this->hostingPlan();
        $price = $this->price($plan);

        $res = $this->post('/websites', [
            'customer_id' => $customer->id,
            'name' => 'Main site',
            'url' => 'https://acme.test',
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
        ]);

        $res->assertSessionHasNoErrors();

        // No CustomerProduct was needed or created — the decoupling.
        $this->assertSame(0, CustomerProduct::count());

        $website = Website::sole();
        $this->assertSame($plan->id, $website->plan_id);
        $this->assertSame($price->id, $website->plan_price_id);
        $this->assertSame('active', $website->hosting_status);
        $this->assertNotNull($website->hosting_started_at);
    }

    public function test_updating_a_website_to_attach_hosting_sets_status_and_started(): void
    {
        $customer = $this->customer();
        $plan = $this->hostingPlan();
        $price = $this->price($plan);

        $website = Website::create([
            'customer_id' => $customer->id,
            'name' => 'Site',
            'url' => 'https://acme.test',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $this->assertSame('none', $website->fresh()->hosting_status);

        $this->put("/websites/{$website->id}", [
            'customer_id' => $customer->id,
            'name' => 'Site',
            'url' => 'https://acme.test',
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
        ])->assertSessionHasNoErrors();

        $fresh = $website->fresh();
        $this->assertSame('active', $fresh->hosting_status);
        $this->assertSame($plan->id, $fresh->plan_id);
        $this->assertNotNull($fresh->hosting_started_at);
    }

    public function test_clearing_the_plan_sets_hosting_status_none(): void
    {
        $customer = $this->customer();
        $plan = $this->hostingPlan();
        $price = $this->price($plan);

        $website = Website::create([
            'customer_id' => $customer->id,
            'name' => 'Site',
            'url' => 'https://acme.test',
            'status' => 'active',
            'created_by' => $this->user->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'hosting_status' => 'active',
            'hosting_started_at' => now(),
        ]);

        $this->put("/websites/{$website->id}", [
            'customer_id' => $customer->id,
            'name' => 'Site',
            'url' => 'https://acme.test',
            'plan_id' => null,
        ])->assertSessionHasNoErrors();

        $fresh = $website->fresh();
        $this->assertSame('none', $fresh->hosting_status);
        $this->assertNull($fresh->plan_id);
        $this->assertNull($fresh->plan_price_id);
    }

    public function test_a_non_hosting_plan_is_rejected_as_hosting(): void
    {
        $customer = $this->customer();
        $domainPlan = $this->hostingPlan(isDomain: true);

        $this->post('/websites', [
            'customer_id' => $customer->id,
            'name' => 'Main site',
            'url' => 'https://acme.test',
            'plan_id' => $domainPlan->id,
        ])->assertSessionHasErrors(['plan_id']);

        $this->assertSame(0, Website::count());
    }
}
