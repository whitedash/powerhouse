<?php

namespace Tests\Feature;

use App\Models\BillingEntity;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\CustomerReferral;
use App\Models\Invoice;
use App\Models\MaavelusStatement;
use App\Models\Product;
use App\Models\Referrer;
use App\Models\User;
use App\Services\MaavelusStatementService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionEngineTest extends TestCase
{
    use RefreshDatabase;

    private int $entityId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = User::factory()->create(['role' => 'super_admin'])->id;
        $this->entityId = BillingEntity::create([
            'name' => 'Test Entity',
            'legal_name' => 'Test Entity Ltd',
            'postmark_sender_email' => 'billing@test.test',
            'postmark_sender_name' => 'Test',
        ])->id;
    }

    /** Active referrer attributed to a customer. */
    private function attributedReferrer(bool $active = true): Referrer
    {
        return Referrer::factory()->create(['is_active' => $active]);
    }

    private function customerWithReferrer(Referrer $referrer): Customer
    {
        $customer = Customer::create(['name' => 'Cust '.uniqid()]);
        CustomerReferral::create([
            'customer_id' => $customer->id,
            'referrer_id' => $referrer->id,
            'source' => 'manual',
            'attributed_at' => now(),
        ]);

        return $customer;
    }

    private function product(string $slug): Product
    {
        return Product::create(['slug' => $slug, 'name' => ucfirst($slug)]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function rule(int $productId, string $type, array $config, ?int $referrerId = null): CommissionRule
    {
        return CommissionRule::create([
            'referrer_id' => $referrerId,
            'product_id' => $productId,
            'type' => $type,
            'config' => $config,
            'valid_from' => now()->subYear(),
            'is_active' => true,
        ]);
    }

    private function invoice(int $customerId, string $type, float $amount, ?int $productId, string $number): Invoice
    {
        $inv = Invoice::create([
            'number' => $number,
            'customer_id' => $customerId,
            'billing_entity_id' => $this->entityId,
            'type' => $type,
            'status' => 'sent',
            'subtotal' => $amount,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'issue_date' => now(),
            'due_date' => now()->addDays(14),
            'created_by' => $this->userId,
        ]);
        $inv->lines()->create([
            'description' => 'Line',
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
            'product_id' => $productId,
            'sort_order' => 0,
        ]);

        return $inv;
    }

    private function pay(Invoice $invoice, string $suffix = 'a'): void
    {
        app(StripeService::class)->markInvoicePaid($invoice, 'sess_'.$invoice->id.$suffix, 'pi_'.$invoice->id.$suffix);
    }

    public function test_one_off_paid_invoice_accrues_one_pending_row_at_percentage(): void
    {
        $referrer = $this->attributedReferrer();
        $customer = $this->customerWithReferrer($referrer);
        $product = $this->product('orderpad');
        $this->rule($product->id, 'one_off_pct', ['percentage' => 10]);

        $this->pay($this->invoice($customer->id, 'service', 500.0, $product->id, 'INV-1'));

        $rows = CommissionLedger::where('customer_id', $customer->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('50.00', (string) $rows[0]->commission_amount);
        $this->assertSame('pending', $rows[0]->status);
        $this->assertSame('onboarding', $rows[0]->trigger_type);
        $this->assertSame($referrer->id, $rows[0]->referrer_id);
    }

    public function test_flat_amount_rule_accrues_flat(): void
    {
        $referrer = $this->attributedReferrer();
        $customer = $this->customerWithReferrer($referrer);
        $product = $this->product('orderpad');
        $this->rule($product->id, 'one_off_pct', ['flat_amount' => 75]);

        $this->pay($this->invoice($customer->id, 'service', 999.0, $product->id, 'INV-FLAT'));

        $this->assertSame('75.00', (string) CommissionLedger::where('customer_id', $customer->id)->sole()->commission_amount);
    }

    public function test_recurring_subscription_invoice_accrues_recurring(): void
    {
        $referrer = $this->attributedReferrer();
        $customer = $this->customerWithReferrer($referrer);
        $product = $this->product('orderpad');
        $this->rule($product->id, 'hybrid', ['recurring_percentage' => 5]);

        $this->pay($this->invoice($customer->id, 'subscription', 200.0, $product->id, 'INV-SUB1'));

        $row = CommissionLedger::where('customer_id', $customer->id)->sole();
        $this->assertSame('10.00', (string) $row->commission_amount);
        $this->assertSame('invoice_paid', $row->trigger_type);
    }

    public function test_recurring_cap_stops_at_n(): void
    {
        $referrer = $this->attributedReferrer();
        $customer = $this->customerWithReferrer($referrer);
        $product = $this->product('orderpad');
        $this->rule($product->id, 'hybrid', ['recurring_percentage' => 5, 'recurring_months' => 2]);

        foreach (['S1', 'S2', 'S3'] as $i => $n) {
            $this->pay($this->invoice($customer->id, 'subscription', 200.0, $product->id, 'INV-'.$n), (string) $i);
        }

        // Cap of 2 → only two recurring rows accrue.
        $this->assertSame(2, CommissionLedger::where('customer_id', $customer->id)->count());
    }

    public function test_lifetime_recurring_keeps_accruing(): void
    {
        $referrer = $this->attributedReferrer();
        $customer = $this->customerWithReferrer($referrer);
        $product = $this->product('orderpad');
        // No recurring_months → lifetime.
        $this->rule($product->id, 'hybrid', ['recurring_percentage' => 5]);

        foreach (['L1', 'L2', 'L3'] as $i => $n) {
            $this->pay($this->invoice($customer->id, 'subscription', 200.0, $product->id, 'INV-'.$n), (string) $i);
        }

        $this->assertSame(3, CommissionLedger::where('customer_id', $customer->id)->count());
    }

    public function test_inactive_referrer_accrues_nothing(): void
    {
        $referrer = $this->attributedReferrer(active: false);
        $customer = $this->customerWithReferrer($referrer);
        $product = $this->product('orderpad');
        $this->rule($product->id, 'one_off_pct', ['percentage' => 10]);

        $this->pay($this->invoice($customer->id, 'service', 500.0, $product->id, 'INV-INACTIVE'));

        $this->assertSame(0, CommissionLedger::count());
    }

    public function test_maavelus_product_is_skipped_by_engine(): void
    {
        $referrer = $this->attributedReferrer();
        $customer = $this->customerWithReferrer($referrer);
        $maavelus = $this->product('maavelus');
        $this->rule($maavelus->id, 'one_off_pct', ['percentage' => 10]);

        $this->pay($this->invoice($customer->id, 'service', 500.0, $maavelus->id, 'INV-MAAV'));

        // Maavelus runs on its statement flow — the invoice engine must not touch it.
        $this->assertSame(0, CommissionLedger::count());
    }

    public function test_webhook_retry_double_pay_yields_one_row(): void
    {
        $referrer = $this->attributedReferrer();
        $customer = $this->customerWithReferrer($referrer);
        $product = $this->product('orderpad');
        $this->rule($product->id, 'one_off_pct', ['percentage' => 10]);

        $invoice = $this->invoice($customer->id, 'service', 500.0, $product->id, 'INV-RETRY');
        $this->pay($invoice, 'first');
        $this->pay($invoice->fresh(), 'second'); // webhook retry / second settle path

        $this->assertSame(1, CommissionLedger::where('customer_id', $customer->id)->count());
    }

    public function test_maavelus_statement_output_unchanged_after_refactor(): void
    {
        // Maavelus generation uses the SAME shared resolver + calculator now.
        $referrer = $this->attributedReferrer();
        $customer = $this->customerWithReferrer($referrer);
        $maavelus = $this->product('maavelus');
        // one_off_pct @ 10% — the historic Maavelus formula: fees * pct / 100.
        $this->rule($maavelus->id, 'one_off_pct', ['percentage' => 10]);

        $statement = MaavelusStatement::create([
            'period_start' => now()->startOfMonth()->subMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'total_fees' => 1000,
            'total_orders' => 10,
            'status' => 'draft',
            'created_by' => $this->userId,
        ]);
        $statement->lines()->create([
            'customer_id' => $customer->id,
            'total_fees' => 1000,
            'order_count' => 10,
        ]);

        app(MaavelusStatementService::class)->generateCommissions($statement->fresh());

        $row = CommissionLedger::where('customer_id', $customer->id)->sole();
        $this->assertSame('100.00', (string) $row->commission_amount); // 1000 * 10%
        $this->assertSame('monthly_recurring', $row->trigger_type);
        $this->assertNull($row->invoice_id);
    }
}
