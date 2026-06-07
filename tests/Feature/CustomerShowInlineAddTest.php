<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer detail page powers the in-context "Add domain" modal, whose
 * TLD picker is fed by the new domain_tlds prop (active is_domain plans only).
 * The store endpoints themselves (/domains, /projects) are covered elsewhere;
 * this just pins the prop the controller now passes.
 */
class CustomerShowInlineAddTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_passes_active_domain_plan_tlds_for_the_add_domain_modal(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $customer = Customer::create(['name' => 'Acme '.uniqid()]);

        $product = Product::create(['slug' => 'dom-'.uniqid(), 'name' => 'Domains']);
        // Active domain plan → its TLD should surface in domain_tlds.
        ProductPlan::create([
            'product_id' => $product->id,
            'name' => '.com renewal',
            'is_domain' => true,
            'is_active' => true,
            'is_public' => true,
            'tld' => '.com',
        ]);
        // Inactive domain plan → must NOT surface (auto-renew can't bill on it).
        ProductPlan::create([
            'product_id' => $product->id,
            'name' => '.net renewal (retired)',
            'is_domain' => true,
            'is_active' => false,
            'is_public' => true,
            'tld' => '.net',
        ]);

        $this->actingAs($staff)
            ->get("/customers/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Internal/Customers/Show')
                ->has('domain_tlds', 1)
                ->where('domain_tlds.0.tld', '.com')
                ->where('domain_tlds.0.plan_name', '.com renewal')
            );
    }
}
