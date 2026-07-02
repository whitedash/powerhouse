<?php

namespace App\Services;

use App\Enums\CommissionTrigger;
use App\Models\ActivityLog;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Referrer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Commission engine — invoice-paid accrual (Sprint 1).
 *
 * Two layers:
 *  - accrue(): the source-agnostic core. Given a credited referrer + the
 *    revenue facts, it resolves the rule, computes the payout, and writes
 *    ONE pending commission_ledger row. A future external/HMAC ingest reuses
 *    this verbatim — only the Stripe invoice-paid trigger is wired today.
 *  - accrueForInvoice(): the Stripe trigger. Reads the paid invoice's lines
 *    (product_id lives on the LINES, not the header), groups by product, and
 *    calls accrue() once per product with one-off vs recurring semantics.
 *
 * Maavelus stays on its own statement flow and is excluded here (config
 * referrals.commission_excluded_slugs) so its revenue is never double-counted.
 * The calculator + rule resolver are shared with MaavelusStatementService so
 * both paths compute identically.
 */
class CommissionService
{
    public function __construct(private CommissionRuleResolver $resolver) {}

    /**
     * Compute a payout from a rule + gross amount.
     *
     * Flat amount (config.flat_amount) takes precedence; otherwise a
     * percentage by rule type. recurring_tiered is deferred (Sprint 2) and
     * stays stubbed at 0 — matching the prior MaavelusStatementService math,
     * so existing rules (which carry no flat_amount) are byte-identical.
     */
    public function calculate(CommissionRule $rule, float $gross): float
    {
        $config = $rule->config ?? [];

        if (isset($config['flat_amount']) && (float) $config['flat_amount'] > 0) {
            return round((float) $config['flat_amount'], 2);
        }

        return match ($rule->type) {
            'hybrid' => round($gross * (float) ($config['recurring_percentage'] ?? 0) / 100, 2),
            'one_off_pct' => round($gross * (float) ($config['percentage'] ?? 0) / 100, 2),
            // recurring_tiered: deferred — stubbed (was 0 in Maavelus too).
            default => 0.0,
        };
    }

    /**
     * Source-agnostic accrual core: resolve the rule, compute, and write one
     * PENDING ledger row. Returns the row, or null when there's no rule, a
     * zero payout, or the invoice×referrer×product was already accrued.
     */
    public function accrue(
        Referrer $referrer,
        Company $customer,
        Product $product,
        float $gross,
        CommissionTrigger $trigger,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?Invoice $invoice = null,
    ): ?CommissionLedger {
        $rule = $this->resolver->resolve($referrer->id, $product->id, $periodStart, $periodEnd);
        if (! $rule) {
            return null;
        }

        $amount = $this->calculate($rule, $gross);
        if ($amount <= 0) {
            return null;
        }

        // App-level idempotency for invoice-scoped accruals: a webhook retry
        // or the multi-path settle (checkout.completed + payment.succeeded +
        // portal confirm) must never double-credit the same invoice line. The
        // DB unique index (invoice_id, referrer_id, product_id) is the hard
        // backstop behind this check.
        if ($invoice !== null
            && CommissionLedger::where('invoice_id', $invoice->id)
                ->where('referrer_id', $referrer->id)
                ->where('product_id', $product->id)
                ->exists()
        ) {
            return null;
        }

        return DB::transaction(function () use ($referrer, $customer, $product, $gross, $amount, $trigger, $periodStart, $periodEnd, $invoice, $rule): CommissionLedger {
            $row = CommissionLedger::create([
                'referrer_id' => $referrer->id,
                'customer_id' => $customer->id,
                'invoice_id' => $invoice?->id,
                'rule_id' => $rule->id,
                'product_id' => $product->id,
                'trigger_type' => $trigger->value,
                'gross_amount' => $gross,
                'commission_amount' => $amount,
                'status' => 'pending',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            ActivityLog::create([
                'user_id' => null,
                'user_role' => 'system',
                'action' => 'commission.accrued',
                'entity_type' => 'commission_ledger',
                'entity_id' => $row->id,
                'after' => [
                    'referrer_id' => $referrer->id,
                    'customer_id' => $customer->id,
                    'invoice_id' => $invoice?->id,
                    'product_id' => $product->id,
                    'trigger' => $trigger->value,
                    'gross' => $gross,
                    'commission' => $amount,
                ],
                'ip_address' => null,
                'user_agent' => 'commission-engine',
            ]);

            return $row;
        });
    }

    /**
     * Accrue commissions for a freshly-paid invoice. Idempotent. Does nothing
     * unless the invoice is paid and its customer has an attribution row
     * pointing at an ACTIVE referrer.
     */
    public function accrueForInvoice(Invoice $invoice): void
    {
        $invoice = Invoice::with(['lines', 'customer'])->find($invoice->id);
        if (! $invoice || $invoice->status !== 'paid' || ! $invoice->customer) {
            return;
        }

        // Who to credit: the customer's immutable attribution → an ACTIVE
        // referrer. No attribution, or an inactive referrer → nothing.
        $referral = $invoice->customer->referral;
        if (! $referral) {
            return;
        }
        $referrer = Referrer::where('id', $referral->referrer_id)->where('is_active', true)->first();
        if (! $referrer) {
            return;
        }

        $excluded = array_map('strval', (array) config('referrals.commission_excluded_slugs', []));
        $isRecurring = $invoice->type === 'subscription';
        [$periodStart, $periodEnd] = $this->invoicePeriod($invoice);

        // Accrue once per product on the summed line amount (product_id is on
        // the lines, not the invoice header).
        $grossByProduct = [];
        foreach ($invoice->lines as $line) {
            if ($line->product_id === null) {
                continue;
            }
            $grossByProduct[$line->product_id] = ($grossByProduct[$line->product_id] ?? 0.0) + (float) $line->amount;
        }

        foreach ($grossByProduct as $productId => $gross) {
            $product = Product::find($productId);
            if (! $product || in_array($product->slug, $excluded, true)) {
                continue; // Maavelus + any statement-managed product
            }

            $rule = $this->resolver->resolve($referrer->id, (int) $productId, $periodStart, $periodEnd);
            if (! $rule) {
                continue;
            }

            if ($isRecurring) {
                // Recurring accrual, capped by config.recurring_months
                // (null/0 = lifetime). Each period is its own invoice, so the
                // per-invoice idempotency never blocks a legitimate renewal.
                $cap = (int) ($rule->config['recurring_months'] ?? 0);
                $already = CommissionLedger::where('referrer_id', $referrer->id)
                    ->where('customer_id', $invoice->customer_id)
                    ->where('product_id', $productId)
                    ->where('trigger_type', CommissionTrigger::InvoicePaid->value)
                    ->count();
                if ($cap > 0 && $already >= $cap) {
                    continue;
                }
                $trigger = CommissionTrigger::InvoicePaid;
            } else {
                // One-off: once per customer×product, ever.
                $exists = CommissionLedger::where('customer_id', $invoice->customer_id)
                    ->where('product_id', $productId)
                    ->where('trigger_type', CommissionTrigger::Onboarding->value)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $trigger = CommissionTrigger::Onboarding;
            }

            $this->accrue($referrer, $invoice->customer, $product, (float) $gross, $trigger, $periodStart, $periodEnd, $invoice);
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function invoicePeriod(Invoice $invoice): array
    {
        // All four are date/datetime casts (Carbon), with now() as the final
        // fallback — so the period bounds are always Carbon.
        $start = $invoice->issue_date ?? $invoice->paid_at ?? now();
        $end = $invoice->recurring_next_date ?? $invoice->due_date ?? $start;

        return [$start, $end];
    }
}
