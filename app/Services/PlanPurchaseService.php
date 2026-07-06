<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PlanCheckoutAttempt;
use App\Models\ProductPlanPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook-driven provisioning for Plans-widget purchases
 * (PLANS-WIDGET-DESIGN.md §4). Called from the Stripe
 * checkout.session.completed handler when the session metadata carries a
 * plan_price_id (the invoice_id path is the pre-existing invoice
 * settlement and never enters here).
 *
 * Everything runs as the system actor (no authenticated user): the
 * people-layer rows log user_role='system' via the nullable-actor
 * PersonService convention, and the rows written here mirror
 * StripeService::markInvoicePaid's audit shape.
 *
 * Crash/replay safety: the invoice is stamped with the Checkout session id
 * at creation, so a webhook retry that already provisioned skips straight
 * to the (idempotent) settle. Event-level dedupe in webhook_events is the
 * first line; this guard covers a crash between provisioning and
 * markProcessed.
 */
class PlanPurchaseService
{
    public function __construct(
        private readonly CompanyProvisioningService $provisioner,
        private readonly StripeService $stripe,
    ) {}

    /**
     * The billing entity that invoices a purchase of this price: the
     * product's own entity, else the first active one — the same fallback
     * the subscription-invoicer sweep and the proposal schedule generator
     * use for a NULL entity.
     */
    public function resolveEntity(ProductPlanPrice $price): ?BillingEntity
    {
        return $price->plan->product->billingEntity
            ?? BillingEntity::where('is_active', true)->first();
    }

    /**
     * Gross charge breakdown for a price: VAT at the billing entity's
     * effective rate (a non-registered entity forces 0 via
     * effective_vat_rate), mirroring the proposal schedule generator.
     * Used by BOTH the checkout-initiation endpoint (the amount Stripe
     * charges) and the webhook settle (the invoice values), so the two
     * can never disagree by construction.
     *
     * @return array{subtotal: float, vat_rate: float, vat_amount: float, total: float}
     */
    public function totals(ProductPlanPrice $price): array
    {
        $entity = $this->resolveEntity($price);
        $subtotal = (float) $price->price;
        $vatRate = $entity !== null ? (float) $entity->effective_vat_rate : 20.0;
        $vatAmount = round($subtotal * ($vatRate / 100), 2);

        return [
            'subtotal' => $subtotal,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total' => round($subtotal + $vatAmount, 2),
        ];
    }

    /**
     * Provision (Company + Contact + Person + CustomerProduct + invoice)
     * and settle a completed plan-purchase Checkout session. Returns null
     * when the metadata doesn't resolve to a live catalog row — logged,
     * not thrown, so Stripe doesn't retry a permanently-bad payload.
     *
     * Deliberately does NOT re-check is_public/is_active here: the
     * checkout endpoint verified them at session creation, and by now the
     * money has moved — an unpublished-mid-checkout plan must still
     * provision what was paid for.
     */
    public function settle(
        string $sessionId,
        string $paymentIntentId,
        int $planPriceId,
        string $purchaserName,
        string $purchaserEmail,
        ?int $amountTotalPence,
    ): ?PlanPurchaseResult {
        $price = ProductPlanPrice::with('plan.product.billingEntity')->find($planPriceId);
        $plan = $price?->plan;
        $product = $plan?->product;

        if ($price === null || $plan === null || $product === null) {
            Log::warning('plans.purchase_unresolvable', [
                'session_id' => $sessionId,
                'plan_price_id' => $planPriceId,
            ]);

            return null;
        }

        // Replay/crash guard: a session that already produced an invoice
        // only needs the idempotent settle (markInvoicePaid re-checks the
        // paid status under a row lock).
        $existing = Invoice::where('stripe_checkout_session_id', $sessionId)->first();
        if ($existing !== null) {
            return $this->settleExisting($existing, $sessionId, $paymentIntentId, $price);
        }

        $totals = $this->totals($price);

        // Design Q11 (amount mismatch): a price/VAT edit between session
        // creation and settlement is the only way these can differ. The
        // money has settled, so provision from the current catalog values
        // and flag loudly rather than hold the purchase hostage.
        if ($amountTotalPence !== null && $amountTotalPence !== (int) round($totals['total'] * 100)) {
            Log::warning('plans.purchase_amount_mismatch', [
                'session_id' => $sessionId,
                'plan_price_id' => $price->id,
                'charged_pence' => $amountTotalPence,
                'catalog_pence' => (int) round($totals['total'] * 100),
            ]);
        }

        $entity = $this->resolveEntity($price);
        $pending = (bool) $plan->requires_manual_review;

        /** @var array{0: Invoice, 1: CustomerProduct, 2: CompanyProvisionResult} $created */
        $created = DB::transaction(function () use (
            $price, $plan, $product, $entity, $totals, $pending, $sessionId, $purchaserName, $purchaserEmail
        ): array {
            // Same funnel as internal customer creation / lead conversion,
            // with the system actor. Person dedupe by email is unchanged —
            // a returning purchaser links to their existing Person. The
            // company itself is always freshly minted (design Q6: no
            // company name is collected in v1, so the purchaser's name is
            // the company name).
            $provision = $this->provisioner->provision(
                [
                    'name' => $purchaserName,
                    'type' => 'other',
                    'country' => 'GB',
                    'pipeline_stage' => 'active',
                    'acquisition_channel' => 'landing_page',
                    'channel_detail' => 'plans-widget:'.$product->slug,
                ],
                [
                    'name' => $purchaserName,
                    'email' => $purchaserEmail,
                    'role' => 'owner',
                ],
                null,
            );
            $company = $provision->company;

            $this->logSystem('customer.created', 'customer', $company->id, [
                'name' => $company->name,
                'via' => 'plans-widget',
            ]);

            $customerProduct = CustomerProduct::create([
                'customer_id' => $company->id,
                'product_id' => $product->id,
                'plan_id' => $plan->id,
                'plan_price_id' => $price->id,
                'billing_entity_id' => $entity?->id,
                'interval_count' => $price->interval_count,
                'interval_unit' => $price->interval_unit,
                // requires_manual_review holds the subscription at
                // 'pending' — the state the internal Provisioning page
                // already surfaces for approval. The invoice below is
                // still marked paid either way: the money has settled and
                // the ledger must say so.
                'status' => $pending ? 'pending' : 'active',
                'started_at' => now(),
                'auto_invoice' => false,
            ]);

            $this->logSystem('customer_product.purchased', 'customer_product', $customerProduct->id, [
                'product' => $product->name,
                'plan' => $plan->name,
                'status' => $customerProduct->status,
                'pending_review' => $pending,
            ]);

            $invoice = Invoice::create([
                'customer_id' => $company->id,
                'billing_entity_id' => $entity?->id,
                'number' => Invoice::generateNextNumber(),
                'type' => 'subscription',
                'status' => 'sent',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'subtotal' => $totals['subtotal'],
                'vat_rate' => $totals['vat_rate'],
                'vat_amount' => $totals['vat_amount'],
                'total' => $totals['total'],
                'notes' => 'Plans widget purchase — '.$product->name.' / '.$plan->name,
                // Stamped NOW (not only by markInvoicePaid) so a webhook
                // retry after a crash finds this invoice and skips
                // re-provisioning.
                'stripe_checkout_session_id' => $sessionId,
                'created_by' => null,
            ]);

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'product_id' => $product->id,
                'plan_id' => $plan->id,
                'description' => $product->name.' — '.$plan->name,
                'quantity' => 1,
                'unit_price' => $totals['subtotal'],
                'amount' => $totals['subtotal'],
                'sort_order' => 0,
            ]);

            $this->logSystem('invoice.plan_purchase_generated', 'invoice', $invoice->id, [
                'number' => $invoice->number,
                'total' => $totals['total'],
                'plan_price_id' => $price->id,
            ]);

            return [$invoice, $customerProduct, $provision];
        });

        [$invoice, $customerProduct, $provision] = $created;

        // Settle through the ONE settlement path — invoice → paid, payments
        // ledger upsert by PI, invoice.paid audit row, nav-cache-neutral
        // reinstate sweep (fresh customer: no-op), and the post-commit
        // referral-commission accrual, all exactly as invoice payments
        // behave today.
        $this->stripe->markInvoicePaid($invoice, $sessionId, $paymentIntentId);

        $this->markAttemptCompleted($sessionId);

        return new PlanPurchaseResult(
            $invoice->fresh() ?? $invoice,
            $customerProduct,
            $provision->company,
            $provision->contact?->email,
            receiptDue: $customerProduct->status === 'active',
        );
    }

    /**
     * A retried delivery whose provisioning already committed: settle
     * idempotently and re-derive the result. receiptDue only when THIS
     * call made the unpaid→paid transition (a crash after provisioning
     * but before settle), never on a plain replay of a settled purchase.
     */
    private function settleExisting(
        Invoice $invoice,
        string $sessionId,
        string $paymentIntentId,
        ProductPlanPrice $price,
    ): PlanPurchaseResult {
        $wasPaid = $invoice->status === 'paid';

        $this->stripe->markInvoicePaid($invoice, $sessionId, $paymentIntentId);

        $this->markAttemptCompleted($sessionId);

        $customerProduct = CustomerProduct::where('customer_id', $invoice->customer_id)
            ->where('plan_price_id', $price->id)
            ->latest('id')
            ->first();

        $invoice->loadMissing('customer.primaryContact');
        $company = $invoice->customer;
        if ($company === null) {
            // customer_id is a non-null FK — reaching here means the row
            // was deleted between provisioning and this retry.
            throw new \RuntimeException("Invoice {$invoice->id} has no customer to settle a plan purchase against.");
        }

        return new PlanPurchaseResult(
            $invoice->fresh() ?? $invoice,
            $customerProduct,
            $company,
            $company->primaryContact?->email,
            receiptDue: ! $wasPaid && $customerProduct?->status === 'active',
        );
    }

    /**
     * Close the abandoned-checkout tracking loop: the attempt row written
     * at checkout-init flips to completed on settlement. UNCONDITIONAL on
     * prior status — a session stays payable up to Stripe's 24h expiry,
     * so a purchase completing after the reconciler marked the attempt
     * abandoned must still end up completed (the truth wins over the
     * earlier staff alert).
     */
    private function markAttemptCompleted(string $sessionId): void
    {
        PlanCheckoutAttempt::where('stripe_checkout_session_id', $sessionId)
            ->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function logSystem(string $action, string $entityType, int $entityId, array $after): void
    {
        ActivityLog::create([
            'user_id' => null,
            'user_role' => 'system',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'after' => $after,
            'ip_address' => null,
            'user_agent' => 'stripe-webhook',
        ]);
    }
}
