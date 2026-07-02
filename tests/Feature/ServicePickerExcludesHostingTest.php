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
 * Stage 3: hosting plans are managed via a website, never as a CustomerProduct
 * — so they're absent from the enable/"Services" picker AND blocked at both
 * server guards (mirroring the is_domain exclusion). Genuine service plans
 * (neither is_hosting nor is_domain) still enable.
 */
class ServicePickerExcludesHostingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($this->user);
    }

    /**
     * @return array{0: Product, 1: ProductPlan, 2: ProductPlanPrice}
     */
    private function planWithPrice(string $kind): array
    {
        $product = Product::create(['slug' => $kind.'-'.uniqid(), 'name' => ucfirst($kind), 'is_active' => true]);
        $plan = ProductPlan::create([
            'product_id' => $product->id,
            'name' => ucfirst($kind).' plan',
            'is_active' => true,
            'is_public' => true,
            'is_hosting' => $kind === 'hosting',
            'is_domain' => false,
        ]);
        $price = ProductPlanPrice::create([
            'plan_id' => $plan->id,
            'price' => 25.00,
            'interval_count' => 1,
            'interval_unit' => 'month',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return [$product, $plan, $price];
    }

    public function test_enable_product_rejects_an_is_hosting_plan(): void
    {
        $customer = Company::create(['name' => 'Acme']);
        [$product, $plan, $price] = $this->planWithPrice('hosting');

        $this->post("/companies/{$customer->id}/products", [
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'status' => 'active',
        ])->assertSessionHasErrors(['plan_id']);

        $this->assertSame(0, CustomerProduct::count());
    }

    public function test_provisioning_toggle_rejects_an_is_hosting_plan(): void
    {
        $customer = Company::create(['name' => 'Acme']);
        [$product, $plan, $price] = $this->planWithPrice('hosting');

        $this->post('/provisioning/toggle', [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'action' => 'enable',
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'status' => 'active',
        ])->assertSessionHas('error');

        $this->assertSame(0, CustomerProduct::count());
    }

    public function test_available_products_picker_excludes_hosting_plans_but_keeps_services(): void
    {
        $customer = Company::create(['name' => 'Acme']);
        [, $hostingPlan] = $this->planWithPrice('hosting');
        [, $servicePlan] = $this->planWithPrice('service');

        $this->get("/companies/{$customer->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Internal/Customers/Show')
                ->where('available_products', function ($products) use ($hostingPlan, $servicePlan): bool {
                    $planIds = collect($products)->flatMap(fn ($p) => $p['plans'])->pluck('id');

                    return $planIds->doesntContain($hostingPlan->id)
                        && $planIds->contains($servicePlan->id);
                })
            );
    }

    public function test_a_genuine_service_plan_still_enables(): void
    {
        $customer = Company::create(['name' => 'Acme']);
        [$product, $plan, $price] = $this->planWithPrice('service');

        $this->post("/companies/{$customer->id}/products", [
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, CustomerProduct::where('customer_id', $customer->id)->where('plan_id', $plan->id)->count());
    }
}
