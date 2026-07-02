<?php

namespace Tests\Feature;

use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Models\BillingEntity;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\StripeService;
use App\Services\WebhookIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Stripe\Event;
use Tests\TestCase;

/**
 * Billing P1: the payments ledger is live from day one (recorded on every
 * settle), checkout vaults the card, the auto-collect flag persists, and NO
 * off-session charging exists yet.
 */
class BillingLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(string $status = 'sent', float $total = 100.0): Invoice
    {
        $entity = BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
        ]);
        $customer = Company::create(['name' => 'Acme '.uniqid()]);
        $user = User::factory()->create(['role' => 'super_admin']);

        return Invoice::create([
            'number' => 'INV-'.random_int(1000, 9999),
            'customer_id' => $customer->id,
            'billing_entity_id' => $entity->id,
            'type' => 'subscription',
            'status' => $status,
            'subtotal' => $total,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total' => $total,
            'amount_paid' => 0,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'created_by' => $user->id,
        ]);
    }

    public function test_mark_invoice_paid_records_a_payment_row(): void
    {
        $invoice = $this->invoice();

        app(StripeService::class)->markInvoicePaid($invoice, 'cs_test', 'pi_test');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'rail' => 'stripe',
            'status' => 'succeeded',
            'stripe_payment_intent_id' => 'pi_test',
        ]);
        $this->assertEqualsWithDelta(100.0, (float) Payment::sole()->amount, 0.001);
    }

    public function test_manual_mark_paid_records_a_payment_row(): void
    {
        $invoice = $this->invoice();
        $staff = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($staff)
            ->post("/invoices/{$invoice->id}/mark-paid", [
                'amount_received' => 100.0,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'bank_transfer',
                'reference' => 'FT-1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'rail' => 'bank',
            'status' => 'succeeded',
            'created_by' => $staff->id,
        ]);
    }

    public function test_checkout_completed_settles_and_vaults_the_card(): void
    {
        $invoice = $this->invoice();

        // Real settle, but stub the card-vaulting (Stripe) call + assert it runs.
        $this->partialMock(StripeService::class, function ($m) {
            $m->shouldReceive('vaultCardFromSession')->once();
        });

        $event = Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_1',
                'payment_intent' => 'pi_1',
                'customer' => 'cus_1',
                'metadata' => ['invoice_id' => (string) $invoice->id],
            ]],
        ]);
        $request = Request::create('/webhooks/stripe', 'POST');
        $request->attributes->set('stripeEvent', $event);

        app(StripeWebhookController::class)->receive(
            $request,
            app(StripeService::class),
            app(WebhookIdempotencyService::class),
        );

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'rail' => 'stripe']);
    }

    public function test_auto_collect_flag_persists(): void
    {
        $invoice = $this->invoice(); // creates a customer + entity
        $customer = $invoice->customer;
        $staff = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($staff)
            ->post("/companies/{$customer->id}/auto-collect", ['auto_collect' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($customer->fresh()->auto_collect);
    }

    public function test_off_session_charging_is_confined_to_one_seam(): void
    {
        // P2 introduces off-session charging, but it must live in exactly ONE
        // place (chargeOffSession) — the on-session checkout path still vaults
        // via setup_future_usage and never opts a checkout session into
        // off_session. (Was: a P1 pin asserting no off-session PI existed at all;
        // P2 is the stage that adds it, so the assertion is inverted here.)
        $source = file_get_contents(app_path('Services/StripeService.php'));
        $this->assertSame(1, substr_count($source, 'PaymentIntent::create'), 'Exactly one off-session charge seam expected.');
        $this->assertStringContainsString("'off_session' => true", $source);
        $this->assertStringContainsString('setup_future_usage', $source);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
