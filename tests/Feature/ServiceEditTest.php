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
 * Editing a service (CustomerProduct) from the company Assets tab: plan, price,
 * active-since, renewal date, status. PUT /customer-products/{id}.
 */
class ServiceEditTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: CustomerProduct, 1: ProductPlanPrice, 2: ProductPlanPrice} */
    private function service(string $status = 'active'): array
    {
        $product = Product::create(['slug' => 'svc-'.uniqid(), 'name' => 'Service', 'is_active' => true]);
        $plan = ProductPlan::create(['product_id' => $product->id, 'name' => 'Pro', 'is_active' => true, 'is_public' => true]);
        $p1 = ProductPlanPrice::create(['plan_id' => $plan->id, 'price' => 20.00, 'interval_count' => 1, 'interval_unit' => 'month', 'is_default' => true, 'is_active' => true]);
        $p2 = ProductPlanPrice::create(['plan_id' => $plan->id, 'price' => 200.00, 'interval_count' => 1, 'interval_unit' => 'year', 'is_default' => false, 'is_active' => true]);
        $customer = Company::create(['name' => 'Acme '.uniqid()]);
        $cp = CustomerProduct::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $p1->id,
            'plan' => 'Pro',
            'price_monthly' => 20.00,
            'interval_count' => 1,
            'interval_unit' => 'month',
            'status' => $status,
            'started_at' => now()->subYear(),
        ]);

        return [$cp, $p1, $p2];
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_editing_a_service_saves_plan_price_dates_and_status(): void
    {
        [$cp, , $p2] = $this->service();

        $this->actingAs($this->staff())
            ->put("/customer-products/{$cp->id}", [
                'plan_id' => $p2->plan_id,
                'plan_price_id' => $p2->id,
                'started_at' => '2025-01-15',
                'next_billing_date' => '2026-01-15',
                'status' => 'active',
                'label' => 'Main instance',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $cp->refresh();
        $this->assertSame($p2->id, $cp->plan_price_id);
        $this->assertEqualsWithDelta(200.00, (float) $cp->price_monthly, 0.001); // from the new price tier
        $this->assertSame('year', $cp->interval_unit);
        $this->assertSame('2025-01-15', $cp->started_at?->toDateString());
        $this->assertSame('2026-01-15', $cp->next_billing_date?->toDateString());
        $this->assertSame('Main instance', $cp->label);
    }

    public function test_status_is_required(): void
    {
        [$cp] = $this->service();

        $this->actingAs($this->staff())
            ->put("/customer-products/{$cp->id}", ['plan_id' => $cp->plan_id])
            ->assertSessionHasErrors('status');
    }

    public function test_trial_requires_an_end_date(): void
    {
        [$cp] = $this->service();

        $this->actingAs($this->staff())
            ->put("/customer-products/{$cp->id}", ['status' => 'trial'])
            ->assertSessionHasErrors('trial_ends_at');
    }

    public function test_non_staff_cannot_edit_a_service(): void
    {
        [$cp, , $p2] = $this->service();
        $referrer = User::factory()->create(['role' => 'referrer']); // not staff

        // Internal routes are gated by role:super_admin,staff + block_referrer
        // middleware (redirect), with the controller's update policy as a second
        // layer. Either way the edit must NOT apply.
        $this->actingAs($referrer)
            ->put("/customer-products/{$cp->id}", ['plan_price_id' => $p2->id, 'status' => 'active'])
            ->assertRedirect();

        $this->assertEqualsWithDelta(20.00, (float) $cp->fresh()->price_monthly, 0.001); // untouched
    }

    public function test_suspended_service_cannot_be_edited(): void
    {
        [$cp, , $p2] = $this->service(status: 'suspended');

        $this->actingAs($this->staff())
            ->put("/customer-products/{$cp->id}", [
                'plan_price_id' => $p2->id,
                'status' => 'active',
            ])
            ->assertSessionHas('error');

        // Unchanged — must go through reinstate, not edit.
        $this->assertSame('suspended', $cp->fresh()->status);
        $this->assertEqualsWithDelta(20.00, (float) $cp->fresh()->price_monthly, 0.001);
    }
}
