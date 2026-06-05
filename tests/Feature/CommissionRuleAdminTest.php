<?php

namespace Tests\Feature;

use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionRuleAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function product(): Product
    {
        return Product::create(['slug' => 'orderpad', 'name' => 'My Order Pad']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $productId, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $productId,
            'referrer_id' => null,
            'mode' => 'one_off',
            'rate_kind' => 'percentage',
            'percentage' => 10,
            'valid_from' => '2026-01-01',
            'is_active' => true,
        ], $overrides);
    }

    public function test_create_one_off_percentage_rule(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->post('/settings/commission-rules', $this->payload($product->id))
            ->assertRedirect();

        $rule = CommissionRule::sole();
        $this->assertSame('one_off_pct', $rule->type);
        $this->assertEquals(['percentage' => 10.0], $rule->config);
    }

    public function test_create_flat_rule(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->post('/settings/commission-rules', $this->payload($product->id, [
                'rate_kind' => 'flat',
                'percentage' => null,
                'flat_amount' => 75,
            ]))
            ->assertRedirect();

        $rule = CommissionRule::sole();
        $this->assertSame('one_off_pct', $rule->type);
        $this->assertEquals(['flat_amount' => 75.0], $rule->config);
    }

    public function test_create_recurring_capped_rule(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->post('/settings/commission-rules', $this->payload($product->id, [
                'mode' => 'recurring',
                'rate_kind' => null,
                'percentage' => null,
                'recurring_percentage' => 5,
                'duration' => 'months',
                'recurring_months' => 6,
            ]))
            ->assertRedirect();

        $rule = CommissionRule::sole();
        $this->assertSame('hybrid', $rule->type);
        $this->assertEquals(['recurring_percentage' => 5.0, 'recurring_months' => 6], $rule->config);
    }

    public function test_create_recurring_lifetime_rule_omits_months(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->post('/settings/commission-rules', $this->payload($product->id, [
                'mode' => 'recurring',
                'rate_kind' => null,
                'percentage' => null,
                'recurring_percentage' => 5,
                'duration' => 'lifetime',
            ]))
            ->assertRedirect();

        $rule = CommissionRule::sole();
        $this->assertSame('hybrid', $rule->type);
        $this->assertArrayNotHasKey('recurring_months', $rule->config);
        $this->assertEquals(['recurring_percentage' => 5.0], $rule->config);
    }

    public function test_overlap_guard_blocks_overlapping_active_rule(): void
    {
        $product = $this->product();
        $admin = $this->admin();

        // First active, open-ended rule.
        $this->actingAs($admin)
            ->post('/settings/commission-rules', $this->payload($product->id, ['valid_from' => '2026-01-01']))
            ->assertRedirect();

        // Second active rule for same product/default, overlapping window.
        $this->actingAs($admin)
            ->post('/settings/commission-rules', $this->payload($product->id, ['valid_from' => '2026-06-01']))
            ->assertSessionHasErrors('valid_from');

        $this->assertSame(1, CommissionRule::count());
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        $product = $this->product();
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)
            ->post('/settings/commission-rules', $this->payload($product->id))
            ->assertForbidden();

        $this->assertSame(0, CommissionRule::count());
    }

    /** The UI must only ever write config the engine can read back. */
    public function test_round_trip_form_to_calculator(): void
    {
        $product = $this->product();
        $admin = $this->admin();

        // One-off % → calculator computes % of gross.
        $this->actingAs($admin)->post('/settings/commission-rules', $this->payload($product->id, ['percentage' => 12]));
        $rule = CommissionRule::sole();
        $this->assertSame(60.0, app(CommissionService::class)->calculate($rule, 500.0)); // 12% of 500

        // Flat → calculator returns the flat regardless of gross.
        $rule->update(['config' => ['flat_amount' => 40]]);
        $this->assertSame(40.0, app(CommissionService::class)->calculate($rule->fresh(), 999.0));

        // Recurring (hybrid) → recurring_percentage of gross.
        $rule->update(['type' => 'hybrid', 'config' => ['recurring_percentage' => 5, 'recurring_months' => 6]]);
        $this->assertSame(10.0, app(CommissionService::class)->calculate($rule->fresh(), 200.0)); // 5% of 200
    }
}
