<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\PortalUser;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 4 fixes: the "Open" button only shows for SSO-capable products, and the
 * subscriptions catalogue offers no dead Subscribe (0-plan products hidden).
 * The Assets page render fix is structural (named slots) — covered by the
 * Stage-4 prop assertions + npm build; the launch contract is asserted here.
 */
class PortalAssetFixesTest extends TestCase
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

    private function serviceCp(Customer $c, string $slug): CustomerProduct
    {
        $product = Product::create(['slug' => $slug, 'name' => ucfirst($slug), 'is_active' => true]);
        $plan = ProductPlan::create(['product_id' => $product->id, 'name' => 'Std', 'is_active' => true, 'is_public' => true]);
        $price = ProductPlanPrice::create(['plan_id' => $plan->id, 'price' => 30.00, 'interval_count' => 1, 'interval_unit' => 'month', 'is_default' => true, 'is_active' => true, 'sort_order' => 0]);

        return CustomerProduct::create([
            'customer_id' => $c->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'status' => 'active',
            'started_at' => now(),
        ]);
    }

    public function test_non_sso_service_exposes_no_launch_target_so_open_is_hidden(): void
    {
        $c = Customer::create(['name' => 'Acme']);
        // 'whitedash' is not an SSO product (getProductConfig knows only
        // maavelus/myorderpad) → no launch target → Open must not render.
        $this->serviceCp($c, 'whitedash');

        $this->actingAs($this->portalUser($c), 'portal')
            ->get('/portal/assets')
            ->assertInertia(fn ($page) => $page
                ->has('services', 1)
                ->where('services.0.sso_enabled', false)
                ->where('services.0.sso_url', null)
            );
    }

    public function test_sso_service_still_exposes_a_launch_target(): void
    {
        $c = Customer::create(['name' => 'Acme']);
        $this->serviceCp($c, 'maavelus');

        $this->actingAs($this->portalUser($c), 'portal')
            ->get('/portal/assets')
            ->assertInertia(fn ($page) => $page
                ->where('services.0.sso_enabled', true)
                ->where('services.0.sso_url', fn ($url) => is_string($url) && $url !== '')
            );
    }

    public function test_catalogue_hides_a_product_with_no_priced_plan(): void
    {
        $c = Customer::create(['name' => 'Acme']);

        // Subscribable: active+public plan WITH an active price.
        $priced = Product::create(['slug' => 'priced-'.uniqid(), 'name' => 'Priced', 'is_active' => true]);
        $pricedPlan = ProductPlan::create(['product_id' => $priced->id, 'name' => 'P', 'is_active' => true, 'is_public' => true]);
        ProductPlanPrice::create(['plan_id' => $pricedPlan->id, 'price' => 10.00, 'interval_count' => 1, 'interval_unit' => 'month', 'is_default' => true, 'is_active' => true, 'sort_order' => 0]);

        // Not subscribable: a public/active plan but NO active price.
        $unpriced = Product::create(['slug' => 'unpriced-'.uniqid(), 'name' => 'Unpriced', 'is_active' => true]);
        ProductPlan::create(['product_id' => $unpriced->id, 'name' => 'U', 'is_active' => true, 'is_public' => true]);

        $this->actingAs($this->portalUser($c), 'portal')
            ->get('/portal/subscriptions')
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Subscriptions')
                ->where('available', function ($products) use ($priced, $unpriced): bool {
                    $ids = collect($products)->pluck('id');

                    return $ids->contains($priced->id) && $ids->doesntContain($unpriced->id);
                })
            );
    }
}
