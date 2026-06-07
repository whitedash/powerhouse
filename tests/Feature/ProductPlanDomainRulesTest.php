<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPlanDomainRulesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function product(): Product
    {
        return Product::create(['slug' => 'domains-'.uniqid(), 'name' => 'Domains']);
    }

    public function test_is_domain_plan_requires_a_tld(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->post('/settings/plans', [
                'product_id' => $product->id,
                'name' => 'No TLD',
                'is_domain' => true,
            ])
            ->assertSessionHasErrors('tld');

        $this->assertSame(0, ProductPlan::count());
    }

    public function test_is_domain_plan_persists_tld_normalised_with_its_single_tier(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->post('/settings/plans', [
                'product_id' => $product->id,
                'name' => '.com renewal',
                'is_domain' => true,
                'tld' => 'COM', // no dot, upper-case
                // The single tier = renewal duration + price.
                'initial_price' => 12.00,
                'initial_interval_count' => 1,
                'initial_interval_unit' => 'year',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('.com', ProductPlan::sole()->tld); // normalised
    }

    public function test_active_domain_plan_requires_exactly_one_price_tier(): void
    {
        $product = $this->product();

        // No price tier → rejected (a domain plan's tier IS its renewal price).
        $this->actingAs($this->admin())
            ->post('/settings/plans', [
                'product_id' => $product->id,
                'name' => '.gr renewal',
                'is_domain' => true,
                'tld' => '.gr',
            ])
            ->assertSessionHasErrors('initial_price');

        $this->assertSame(0, ProductPlan::count());
    }

    public function test_only_one_active_plan_per_tld(): void
    {
        $product = $this->product();
        ProductPlan::create([
            'product_id' => $product->id, 'name' => '.com A', 'is_domain' => true,
            'is_active' => true, 'tld' => '.com',
        ]);

        $this->actingAs($this->admin())
            ->post('/settings/plans', [
                'product_id' => $product->id,
                'name' => '.com B',
                'is_domain' => true,
                'is_active' => true,
                'tld' => '.com',
                'initial_price' => 12.00,
                'initial_interval_count' => 1,
                'initial_interval_unit' => 'year',
            ])
            ->assertSessionHasErrors('tld');

        $this->assertSame(1, ProductPlan::where('tld', '.com')->count());
    }

    public function test_inactive_plan_may_share_a_tld_with_an_active_one(): void
    {
        $product = $this->product();
        ProductPlan::create([
            'product_id' => $product->id, 'name' => '.com active', 'is_domain' => true,
            'is_active' => true, 'tld' => '.com',
        ]);

        // A NEW inactive plan for the same TLD is allowed (only one ACTIVE).
        $this->actingAs($this->admin())
            ->post('/settings/plans', [
                'product_id' => $product->id,
                'name' => '.com draft',
                'is_domain' => true,
                'is_active' => false,
                'tld' => '.com',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ProductPlan::where('tld', '.com')->count());
    }
}
