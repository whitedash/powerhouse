<?php

namespace Tests\Feature;

use App\Mail\FailedPaymentNotice;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\StripeCustomer;
use App\Models\User;
use App\Services\DunningService;
use App\Services\OffSessionCollector;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Billing P3 — dunning. Money safety: every attempt recorded, every failure
 * emailed + alerted, a PER-ATTEMPT idempotency key (no double-charge), and
 * recovery/escalation through the existing backbones. chargeOffSession /
 * createCheckoutSession are mocked; markInvoicePaid + the ledger run for real.
 */
class DunningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.stripe.secret' => '']); // off → relink no-ops unless a test opts in
        User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * Build a dunning-eligible invoice: auto_collect customer, active default
     * card, Stripe mapping, and $failed prior stripe attempts (the first
     * anchored $firstDaysAgo ago).
     *
     * @return array{0: Customer, 1: Invoice}
     */
    private function dunningInvoice(int $failed = 1, int $firstDaysAgo = 4, string $status = 'overdue', string $attemptStatus = 'failed', float $total = 100.0): array
    {
        $entity = BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
        ]);
        $customer = Customer::create(['name' => 'Acme '.uniqid(), 'auto_collect' => true]);
        Contact::create(['customer_id' => $customer->id, 'name' => 'Pat', 'email' => 'pat@acme.test', 'is_primary' => true]);
        StripeCustomer::create(['customer_id' => $customer->id, 'stripe_customer_id' => 'cus_'.$customer->id]);
        PaymentMethod::create([
            'customer_id' => $customer->id, 'stripe_customer_id' => 'cus_'.$customer->id,
            'stripe_payment_method_id' => 'pm_'.$customer->id,
            'brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030,
            'is_default' => true, 'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'number' => 'INV-'.random_int(1000, 9999),
            'customer_id' => $customer->id, 'billing_entity_id' => $entity->id,
            'type' => 'subscription', 'status' => $status,
            'subtotal' => $total, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $total, 'amount_paid' => 0,
            'issue_date' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
            'created_by' => User::first()->id,
        ]);

        for ($i = 0; $i < $failed; $i++) {
            Payment::create([
                'invoice_id' => $invoice->id, 'customer_id' => $customer->id,
                'amount' => $total, 'currency' => 'gbp', 'rail' => 'stripe',
                'status' => $attemptStatus, 'failure_reason' => 'card_declined',
                'stripe_payment_intent_id' => 'pi_seed_'.$invoice->id.'_'.$i,
                'attempted_at' => now()->subDays($firstDaysAgo - $i), 'created_by' => null,
            ]);
        }

        return [$customer, $invoice];
    }

    private function stripe(?array $chargeOutcome = null, ?\Closure $extra = null): StripeService
    {
        return $this->partialMock(StripeService::class, function ($m) use ($chargeOutcome, $extra) {
            if ($chargeOutcome !== null) {
                $m->shouldReceive('chargeOffSession')->andReturn($chargeOutcome);
            }
            if ($extra) {
                $extra($m);
            }
        });
    }

    public function test_due_retry_recharges_with_a_fresh_per_attempt_key(): void
    {
        [$customer, $invoice] = $this->dunningInvoice(failed: 1, firstDaysAgo: 4); // attempt 2 due
        $this->partialMock(StripeService::class, function ($m) {
            $m->shouldReceive('chargeOffSession')->once()
                ->withArgs(fn ($cid, $pmid, $pence, $meta, $key) => str_ends_with($key, '_a2'))
                ->andReturn(['status' => 'succeeded', 'payment_intent_id' => 'pi_retry2', 'failure_reason' => null]);
        });

        app(DunningService::class)->run();

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_retry_success_reinstates_suspended_product_then_dunning_stops(): void
    {
        [$customer, $invoice] = $this->dunningInvoice(failed: 1, firstDaysAgo: 4);
        $product = Product::create(['slug' => 'svc-'.uniqid(), 'name' => 'Svc', 'billing_entity_id' => $invoice->billing_entity_id]);
        $cp = CustomerProduct::create([
            'customer_id' => $customer->id, 'product_id' => $product->id,
            'status' => 'suspended', 'suspension_reason' => 'non_payment',
            'suspended_at' => now()->subDay(), 'suspended_by' => null, // system-suspended
        ]);

        $this->stripe(['status' => 'succeeded', 'payment_intent_id' => 'pi_ok', 'failure_reason' => null]);

        app(DunningService::class)->run();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('active', $cp->fresh()->status); // auto-reinstated
        // One settled ledger row for the recovery PI (no duplicate).
        $this->assertSame(1, Payment::where('stripe_payment_intent_id', 'pi_ok')->count());
    }

    public function test_third_failure_sends_final_email_escalates_and_stops(): void
    {
        [$customer, $invoice] = $this->dunningInvoice(failed: 2, firstDaysAgo: 7); // attempt 3 (final) due
        $this->partialMock(StripeService::class, function ($m) {
            $m->shouldReceive('chargeOffSession')->once()
                ->andReturn(['status' => 'failed', 'payment_intent_id' => 'pi_fail3', 'failure_reason' => 'card_declined']);
        });

        app(DunningService::class)->run();

        // Final dunning email + escalation marker for the existing suspend path.
        Mail::assertSent(FailedPaymentNotice::class, fn ($mail) => $mail->final === true && $mail->attempt === 3);
        $marker = ActivityLog::where('entity_type', 'invoice')->where('entity_id', $invoice->id)
            ->where('action', 'invoice.reminder_sent')->get()
            ->first(fn ($r) => ($r->after['tier'] ?? null) === 'final_notice');
        $this->assertNotNull($marker, 'final_notice escalation marker written');
        $this->assertSame(3, Payment::where('invoice_id', $invoice->id)->where('status', 'failed')->count());

        // Exhausted — a second run does NOT retry again.
        app(DunningService::class)->run(); // chargeOffSession already ->once(); a 2nd call would fail the expectation
        $this->assertSame(3, Payment::where('invoice_id', $invoice->id)->where('status', 'failed')->count());
    }

    public function test_rerun_same_day_does_not_double_charge(): void
    {
        [$customer, $invoice] = $this->dunningInvoice(failed: 1, firstDaysAgo: 4); // attempt 2 due
        $this->partialMock(StripeService::class, function ($m) {
            // ->once(): a same-day second run must NOT charge again.
            $m->shouldReceive('chargeOffSession')->once()
                ->andReturn(['status' => 'failed', 'payment_intent_id' => 'pi_a2', 'failure_reason' => 'card_declined']);
        });

        app(DunningService::class)->run(); // attempt 2 fails → count 2, next due +6 (future)
        app(DunningService::class)->run(); // not due → no charge

        $this->assertSame(2, Payment::where('invoice_id', $invoice->id)->where('status', 'failed')->count());
        $this->assertNotSame('paid', $invoice->fresh()->status);
    }

    public function test_requires_action_resends_link_and_does_not_retry(): void
    {
        config(['services.stripe.secret' => 'sk_test_x']); // enable relink
        [$customer, $invoice] = $this->dunningInvoice(failed: 1, firstDaysAgo: 4, attemptStatus: 'requires_action');

        $this->partialMock(StripeService::class, function ($m) {
            $m->shouldReceive('chargeOffSession')->never(); // SCA: never off-session retry
            $m->shouldReceive('createCheckoutSession')->once()
                ->andReturn(Session::constructFrom(['id' => 'cs_relink', 'url' => 'https://pay.test/x']));
        });

        app(DunningService::class)->run();

        Mail::assertSent(FailedPaymentNotice::class);
        // A fresh requires_action marker advanced the cadence (now 2 attempts).
        $this->assertSame(2, Payment::where('invoice_id', $invoice->id)->where('status', 'requires_action')->count());
    }

    public function test_manual_payment_mid_dunning_exits_cleanly(): void
    {
        [$customer, $invoice] = $this->dunningInvoice(failed: 1, firstDaysAgo: 4);
        $invoice->update(['status' => 'paid', 'amount_paid' => $invoice->total, 'paid_at' => now()]);

        $this->partialMock(StripeService::class, fn ($m) => $m->shouldReceive('chargeOffSession')->never());

        $results = app(DunningService::class)->run();

        $this->assertCount(0, $results); // excluded by status filter
    }

    public function test_active_dunning_invoice_is_excluded_from_reminders(): void
    {
        // In dunning (has a failed stripe attempt) — must NOT get a generic reminder.
        [$dCustomer, $dInvoice] = $this->dunningInvoice(failed: 1, firstDaysAgo: 4);
        $dInvoice->update(['next_reminder_at' => now()->subDay(), 'reminders_paused' => false]);

        // Control: overdue, no stripe attempt — SHOULD get a reminder nudge.
        [$cCustomer, $cInvoice] = $this->dunningInvoice(failed: 0, firstDaysAgo: 4);
        $cInvoice->update(['next_reminder_at' => now()->subDay(), 'reminders_paused' => false]);

        $this->artisan('invoices:send-reminders')->assertExitCode(0);

        $this->assertSame(0, (int) $dInvoice->fresh()->reminder_count, 'dunning invoice not reminded');
        $this->assertGreaterThan(0, (int) $cInvoice->fresh()->reminder_count, 'control invoice reminded');
    }

    public function test_dry_run_charges_and_sends_nothing(): void
    {
        [$customer, $invoice] = $this->dunningInvoice(failed: 1, firstDaysAgo: 4);
        $this->partialMock(StripeService::class, fn ($m) => $m->shouldReceive('chargeOffSession')->never());
        $before = Payment::where('invoice_id', $invoice->id)->count();

        $results = app(DunningService::class)->run(dryRun: true);

        $this->assertSame($before, Payment::where('invoice_id', $invoice->id)->count()); // no new rows
        Mail::assertNothingSent();
        $this->assertSame('would_retry', $results[0]['status']);
    }

    public function test_collect_due_initial_decline_sends_first_dunning_email(): void
    {
        // P2 wiring: the initial off-session decline now sends dunning email #1.
        [$customer, $invoice] = $this->dunningInvoice(failed: 0, firstDaysAgo: 0, status: 'sent');
        $this->stripe(['status' => 'failed', 'payment_intent_id' => 'pi_first', 'failure_reason' => 'card_declined']);

        app(OffSessionCollector::class)->collectInvoice($customer, $invoice->fresh(), false);

        Mail::assertSent(FailedPaymentNotice::class, fn ($mail) => $mail->attempt === 1 && $mail->final === false);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->where('status', 'failed')->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
