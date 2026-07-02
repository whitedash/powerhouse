<?php

namespace Tests\Feature;

use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Mail\InvoiceSent;
use App\Models\BillingEntity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\StripeCustomer;
use App\Models\User;
use App\Services\OffSessionCollector;
use App\Services\StripeService;
use App\Services\WebhookIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Stripe\Checkout\Session;
use Stripe\Event;
use Tests\TestCase;

/**
 * Billing P2 — off-session collection. Money safety is the priority: every
 * attempt recorded, every failure alerted, never a silent break, never a
 * double-charge. The Stripe network is mocked at the chargeOffSession /
 * createCheckoutSession seams; markInvoicePaid + the ledger run for real.
 */
class OffSessionCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Empty secret → autoSend skips real hosted-link generation.
        config(['services.stripe.secret' => '']);
        // invoices.created_by is NOT NULL on some paths; have a system user.
        User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * A fully chargeable customer: auto_collect on, a Stripe-customer mapping,
     * an active default card, and one sent invoice.
     *
     * @return array{0: Company, 1: Invoice}
     */
    private function chargeable(string $status = 'sent', float $total = 100.0, bool $autoCollect = true, bool $withCard = true): array
    {
        $entity = BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
        ]);
        $customer = Company::create(['name' => 'Acme '.uniqid(), 'auto_collect' => $autoCollect]);
        Contact::create([
            'customer_id' => $customer->id, 'name' => 'Pat', 'email' => 'pat@acme.test', 'is_primary' => true,
        ]);
        StripeCustomer::create(['customer_id' => $customer->id, 'stripe_customer_id' => 'cus_'.$customer->id]);

        if ($withCard) {
            PaymentMethod::create([
                'customer_id' => $customer->id,
                'stripe_customer_id' => 'cus_'.$customer->id,
                'stripe_payment_method_id' => 'pm_'.$customer->id,
                'brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030,
                'is_default' => true, 'status' => 'active',
            ]);
        }

        $invoice = Invoice::create([
            'number' => 'INV-'.random_int(1000, 9999),
            'customer_id' => $customer->id,
            'billing_entity_id' => $entity->id,
            'type' => 'subscription',
            'status' => $status,
            'subtotal' => $total, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $total,
            'amount_paid' => 0,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'created_by' => User::first()->id,
        ]);

        return [$customer, $invoice];
    }

    /**
     * partialMock StripeService with chargeOffSession stubbed to one outcome.
     */
    private function stripeReturning(array $outcome, ?\Closure $extra = null): StripeService
    {
        return $this->partialMock(StripeService::class, function ($m) use ($outcome, $extra) {
            $m->shouldReceive('chargeOffSession')->andReturn($outcome);
            if ($extra) {
                $extra($m);
            }
        });
    }

    private function collector(): OffSessionCollector
    {
        return app(OffSessionCollector::class);
    }

    public function test_success_marks_invoice_paid_with_one_ledger_row(): void
    {
        [$customer, $invoice] = $this->chargeable();
        $this->stripeReturning(['status' => 'succeeded', 'payment_intent_id' => 'pi_ok', 'failure_reason' => null]);

        $this->collector()->collectInvoice($customer, $invoice, false);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $row = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('succeeded', $row->status);
        $this->assertSame('pi_ok', $row->stripe_payment_intent_id);
        $this->assertSame('stripe', $row->rail);
        $this->assertNull($row->created_by); // system
    }

    public function test_decline_records_failed_row_leaves_invoice_unpaid_and_alerts(): void
    {
        [$customer, $invoice] = $this->chargeable();
        Log::spy();
        $this->stripeReturning(['status' => 'failed', 'payment_intent_id' => 'pi_dead', 'failure_reason' => 'card_declined']);

        $this->collector()->collectInvoice($customer, $invoice, false);

        $this->assertNotSame('paid', $invoice->fresh()->status);
        $row = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame('card_declined', $row->failure_reason);
        Log::shouldHaveReceived('warning')->with('stripe.collect_failed', Mockery::type('array'))->once();
    }

    public function test_sca_requires_action_emails_link_and_is_not_failed(): void
    {
        [$customer, $invoice] = $this->chargeable();
        $this->stripeReturning(
            ['status' => 'requires_action', 'payment_intent_id' => 'pi_sca', 'failure_reason' => 'authentication_required'],
            fn ($m) => $m->shouldReceive('createCheckoutSession')->andReturn(
                Session::constructFrom(['id' => 'cs_sca', 'url' => 'https://pay.test/sca'])
            ),
        );

        $this->collector()->collectInvoice($customer, $invoice, false);

        $row = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('requires_action', $row->status);
        $this->assertNotSame('paid', $invoice->fresh()->status); // NOT failed, NOT paid yet
        Mail::assertSent(InvoiceSent::class);
    }

    public function test_no_default_payment_method_is_skipped(): void
    {
        [$customer, $invoice] = $this->chargeable(withCard: false);
        Log::spy();
        // chargeOffSession must never run for an unchargeable customer.
        $this->partialMock(StripeService::class, fn ($m) => $m->shouldReceive('chargeOffSession')->never());

        $res = $this->collector()->collectInvoice($customer, $invoice, false);

        $this->assertSame('skipped', $res['status']);
        $this->assertSame('no_default_pm', $res['reason']);
        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
        Log::shouldHaveReceived('warning')->with('stripe.collect_no_default_pm', Mockery::type('array'))->once();
    }

    public function test_sub_minimum_amount_is_skipped(): void
    {
        [$customer, $invoice] = $this->chargeable(total: 0.20); // £0.20 < £0.30
        $this->partialMock(StripeService::class, fn ($m) => $m->shouldReceive('chargeOffSession')->never());

        $res = $this->collector()->collectInvoice($customer, $invoice, false);

        $this->assertSame('skipped', $res['status']);
        $this->assertSame('below_minimum', $res['reason']);
        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
    }

    public function test_paid_and_void_invoices_are_never_charged(): void
    {
        $this->partialMock(StripeService::class, fn ($m) => $m->shouldReceive('chargeOffSession')->never());

        [$cPaid, $paid] = $this->chargeable(status: 'paid');
        [$cVoid, $void] = $this->chargeable(status: 'void');

        // run() filters them out entirely.
        $results = $this->collector()->run(false);

        $this->assertSame(0, Payment::count());
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame('void', $void->fresh()->status);
        $this->assertCount(0, $results);
    }

    public function test_draft_is_auto_sent_then_charged(): void
    {
        [$customer, $invoice] = $this->chargeable(status: 'draft');
        $this->stripeReturning(['status' => 'succeeded', 'payment_intent_id' => 'pi_draft', 'failure_reason' => null]);

        $this->collector()->collectInvoice($customer, $invoice, false);

        // Auto-sent (email) then charged + paid.
        Mail::assertSent(InvoiceSent::class);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('succeeded', Payment::where('invoice_id', $invoice->id)->sole()->status);
    }

    public function test_running_twice_does_not_double_charge_or_double_record(): void
    {
        [$customer, $invoice] = $this->chargeable();
        // chargeOffSession may run at most once across both runs.
        $stripe = $this->partialMock(StripeService::class, function ($m) {
            $m->shouldReceive('chargeOffSession')->once()
                ->andReturn(['status' => 'succeeded', 'payment_intent_id' => 'pi_once', 'failure_reason' => null]);
        });

        app(OffSessionCollector::class)->run(false);
        app(OffSessionCollector::class)->run(false); // second sweep

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_inline_success_and_webhook_converge_to_one_row_and_one_paid_mark(): void
    {
        [$customer, $invoice] = $this->chargeable();
        $this->stripeReturning(['status' => 'succeeded', 'payment_intent_id' => 'pi_conv', 'failure_reason' => null]);

        $this->collector()->collectInvoice($customer, $invoice, false);

        // Now the async payment_intent.succeeded arrives for the SAME PI.
        $event = Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_conv',
                'metadata' => ['invoice_id' => (string) $invoice->id, 'customer_id' => (string) $customer->id],
            ]],
        ]);
        $request = Request::create('/webhooks/stripe', 'POST');
        $request->attributes->set('stripeEvent', $event);
        app(StripeWebhookController::class)->receive($request, app(StripeService::class), app(WebhookIdempotencyService::class));

        // Converged: one ledger row, one paid mark.
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_webhook_payment_failed_records_a_failed_ledger_row(): void
    {
        [$customer, $invoice] = $this->chargeable();

        $event = Event::constructFrom([
            'id' => 'evt_'.uniqid(),
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_wh_fail',
                'amount' => 10000,
                'metadata' => ['invoice_id' => (string) $invoice->id, 'customer_id' => (string) $customer->id],
                'last_payment_error' => ['message' => 'Your card was declined.'],
            ]],
        ]);
        $request = Request::create('/webhooks/stripe', 'POST');
        $request->attributes->set('stripeEvent', $event);
        app(StripeWebhookController::class)->receive($request, app(StripeService::class), app(WebhookIdempotencyService::class));

        $row = Payment::where('stripe_payment_intent_id', 'pi_wh_fail')->sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame('Your card was declined.', $row->failure_reason);
        $this->assertNotSame('paid', $invoice->fresh()->status);
    }

    public function test_dry_run_charges_nothing(): void
    {
        [$customer, $invoice] = $this->chargeable();
        $this->partialMock(StripeService::class, fn ($m) => $m->shouldReceive('chargeOffSession')->never());

        $results = $this->collector()->run(true); // dry run

        $this->assertSame(0, Payment::count());
        $this->assertSame('sent', $invoice->fresh()->status); // untouched
        $this->assertSame('would_charge', $results[0]['status']);
        Mail::assertNothingSent();
    }

    public function test_auto_collect_off_customer_is_never_touched(): void
    {
        [$customer, $invoice] = $this->chargeable(autoCollect: false);
        $this->partialMock(StripeService::class, fn ($m) => $m->shouldReceive('chargeOffSession')->never());

        $results = $this->collector()->run(false);

        $this->assertCount(0, $results);
        $this->assertSame(0, Payment::count());
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
