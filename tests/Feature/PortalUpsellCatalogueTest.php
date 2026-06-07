<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PortalUser;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Portal upsell presentation: the catalogue exposes the EXISTING product
 * description + plan features + a "from £X/mo" headline so the cards/modal can
 * pitch (not just name + plan count). 0-plan products stay excluded, and the
 * request-to-subscribe flow is unchanged.
 */
class PortalUpsellCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private function portalUser(Customer $customer): PortalUser
    {
        return PortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Portal '.uniqid(),
            'email' => 'p'.uniqid().'@test.test',
            'password' => bcrypt('secret-pass-123'),
        ]);
    }

    public function test_catalogue_exposes_description_features_and_from_price(): void
    {
        $customer = Customer::create(['name' => 'Acme']);

        $product = Product::create([
            'slug' => 'comnicube-'.uniqid(),
            'name' => 'ComniCube',
            'description' => 'All-in-one comms platform for hospitality.',
            'is_active' => true,
        ]);
        $plan = ProductPlan::create([
            'product_id' => $product->id,
            'name' => 'Enterprise',
            'description' => 'For larger venues.',
            'features' => ['Unlimited seats', 'Priority support', 'SLA 99.9%'],
            'is_active' => true,
            'is_public' => true,
        ]);
        // Annual £120 → "from £10/mo" (monthly-equivalent).
        ProductPlanPrice::create([
            'plan_id' => $plan->id, 'price' => 120.00, 'interval_count' => 1, 'interval_unit' => 'year',
            'is_default' => true, 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($this->portalUser($customer), 'portal')
            ->get('/portal/subscriptions')
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Subscriptions')
                ->where('available.0.id', $product->id)
                ->where('available.0.description', 'All-in-one comms platform for hospitality.')
                ->where('available.0.from_monthly', fn ($v): bool => (float) $v === 10.0)
                ->where('available.0.plans.0.features', ['Unlimited seats', 'Priority support', 'SLA 99.9%'])
                ->where('available.0.plans.0.description', 'For larger venues.')
                ->has('available.0.plans.0.prices', 1)
            );
    }

    public function test_zero_plan_product_is_excluded_from_catalogue(): void
    {
        $customer = Customer::create(['name' => 'Acme']);

        // Public/active plan but NO active price → not subscribable → product hidden.
        $product = Product::create(['slug' => 'np-'.uniqid(), 'name' => 'NoPrice', 'is_active' => true]);
        ProductPlan::create(['product_id' => $product->id, 'name' => 'Basic', 'is_active' => true, 'is_public' => true]);

        $this->actingAs($this->portalUser($customer), 'portal')
            ->get('/portal/subscriptions')
            ->assertInertia(fn ($page) => $page
                ->where('available', fn ($products) => collect($products)->pluck('id')->doesntContain($product->id))
            );
    }

    public function test_subscribe_request_still_submits_as_pending(): void
    {
        $customer = Customer::create(['name' => 'Acme']);
        $product = Product::create(['slug' => 'svc-'.uniqid(), 'name' => 'Service', 'is_active' => true]);
        $plan = ProductPlan::create(['product_id' => $product->id, 'name' => 'Std', 'is_active' => true, 'is_public' => true]);
        $price = ProductPlanPrice::create(['plan_id' => $plan->id, 'price' => 30.00, 'interval_count' => 1, 'interval_unit' => 'month', 'is_default' => true, 'is_active' => true, 'sort_order' => 0]);

        $this->actingAs($this->portalUser($customer), 'portal')
            ->post('/portal/subscriptions', [
                'product_id' => $product->id,
                'plan_id' => $plan->id,
                'price_id' => $price->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customer_products', [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => 'pending',
        ]);
    }
}
