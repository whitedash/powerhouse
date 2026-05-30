<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Daily sweep that turns active subscriptions into draft invoices on
 * their next_billing_date. Each subscription must explicitly opt in
 * via auto_invoice — the system won't quietly start billing for any
 * existing row.
 *
 * Why draft, not sent: the invoice can still be reviewed (line item
 * description, VAT, dates) before it hits the customer's inbox. Once
 * the email sprint runs the operator can flip the workflow to
 * auto-send if they want.
 *
 * Idempotency: next_billing_date is advanced inside the same
 * transaction that creates the invoice, so a re-run on the same day
 * won't double-bill the same subscription.
 */
class GenerateSubscriptionInvoices extends Command
{
    /** @var string */
    protected $signature = 'invoices:generate-subscriptions '
        .'{--dry-run : List which subscriptions would be invoiced without creating any rows}';

    /** @var string */
    protected $description = 'Generate draft invoices for active subscriptions due for renewal.';

    private const DEFAULT_VAT_RATE = 20.0;

    private const DEFAULT_PAYMENT_TERM_DAYS = 14;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();

        // Eager-load every relation the line-item description needs
        // up front — anything we miss here would lazy-fetch inside the
        // loop and trip the Model::preventLazyLoading() strict mode.
        $subscriptions = CustomerProduct::where('status', 'active')
            ->where('auto_invoice', true)
            ->whereNotNull('next_billing_date')
            ->where('next_billing_date', '<=', $today->toDateString())
            ->with([
                'customer:id,name,city',
                'product:id,name,slug',
                'productPlan:id,name',
                'planPrice:id,plan_id,price,interval_count,interval_unit,label',
                'billingEntity:id,name',
                'autoInvoiceEntity:id,name',
            ])
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No subscriptions due for invoicing today.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d subscription(s) due for invoicing.', $subscriptions->count()));

        // Fallback billing entity used when a subscription doesn't
        // carry an auto_invoice_entity_id of its own. Cached once
        // rather than re-fetched per row.
        $fallbackEntityId = $subscriptions->contains(fn (CustomerProduct $s) => $s->auto_invoice_entity_id === null)
            ? BillingEntity::where('is_active', true)->orderBy('id')->value('id')
            : null;

        $generated = 0;
        $skipped = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $entityId = $subscription->auto_invoice_entity_id ?? $fallbackEntityId;
                if ($entityId === null) {
                    $this->error(sprintf(
                        'Skipped subscription #%d — no billing entity configured.',
                        $subscription->id,
                    ));
                    $skipped++;

                    continue;
                }

                $price = $this->resolvePrice($subscription);
                if ($price <= 0) {
                    $this->error(sprintf(
                        'Skipped subscription #%d — price resolves to £0.',
                        $subscription->id,
                    ));
                    $skipped++;

                    continue;
                }

                $intervalLabel = $this->resolveIntervalLabel($subscription);
                $description = $this->buildLineDescription($subscription, $intervalLabel);

                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] Would invoice %s — £%s · %s',
                        $subscription->customer->name,
                        number_format($price, 2),
                        $description,
                    ));
                    $generated++;

                    continue;
                }

                DB::transaction(function () use ($subscription, $entityId, $price, $description, $today): void {
                    $vatRate = self::DEFAULT_VAT_RATE;
                    $vatAmount = round($price * ($vatRate / 100), 2);
                    $total = round($price + $vatAmount, 2);

                    $dueDate = $today->copy()->addDays(self::DEFAULT_PAYMENT_TERM_DAYS);

                    // generateNextNumber pessimistic-locks the latest
                    // INV-#### row inside this transaction; a parallel
                    // controller store() blocks until COMMIT.
                    $number = Invoice::generateNextNumber();

                    $invoice = Invoice::create([
                        'number' => $number,
                        'customer_id' => $subscription->customer_id,
                        'billing_entity_id' => $entityId,
                        'type' => 'subscription',
                        'status' => 'draft',
                        'subtotal' => $price,
                        'vat_rate' => $vatRate,
                        'vat_amount' => $vatAmount,
                        'total' => $total,
                        'amount_paid' => 0,
                        'issue_date' => $today->toDateString(),
                        'due_date' => $dueDate->toDateString(),
                        'notes' => 'Auto-generated from subscription #'.$subscription->id.'.',
                    ]);

                    InvoiceLine::create([
                        'invoice_id' => $invoice->id,
                        'product_id' => $subscription->product_id,
                        // plan_id maps to the canonical ProductPlan
                        // (not the price row) — matches the invoice_lines
                        // FK shape added in the line-products sprint.
                        'plan_id' => $subscription->plan_id,
                        'description' => $description,
                        'quantity' => 1,
                        'unit_price' => $price,
                        'amount' => $price,
                        'sort_order' => 0,
                    ]);

                    // Advance the subscription's billing cadence before
                    // we let the transaction COMMIT — that way the next
                    // sweep won't re-pick the same row on the same day.
                    $nextDate = $this->advance(
                        $subscription->next_billing_date ?? $today,
                        $this->resolveIntervalCount($subscription),
                        $this->resolveIntervalUnit($subscription),
                    );
                    $subscription->update([
                        'next_billing_date' => $nextDate,
                        'last_invoiced_at' => $today->toDateString(),
                    ]);

                    // MRR is computed from active customer_products on the
                    // dashboard and cached for 2 minutes. Bust it here so
                    // the next page load reflects today's billing.
                    Cache::forget('dash.mrr');

                    ActivityLog::create([
                        'user_id' => null,
                        'user_role' => 'system',
                        'action' => 'invoice.auto_generated',
                        'entity_type' => 'invoice',
                        'entity_id' => $invoice->id,
                        'before' => null,
                        'after' => [
                            'number' => $invoice->number,
                            'customer_id' => $invoice->customer_id,
                            'subscription_id' => $subscription->id,
                            'amount' => $total,
                        ],
                        'ip_address' => null,
                        'user_agent' => 'artisan:invoices:generate-subscriptions',
                    ]);

                    $this->info(sprintf(
                        'Generated %s for %s — £%s',
                        $invoice->number,
                        $subscription->customer->name,
                        number_format($total, 2),
                    ));
                });

                $generated++;
            } catch (Throwable $e) {
                $this->error(sprintf(
                    'Failed subscription #%d: %s',
                    $subscription->id,
                    $e->getMessage(),
                ));
                $skipped++;
            }
        }

        $this->info(sprintf(
            '%d invoice%s generated, %d skipped.%s',
            $generated,
            $generated === 1 ? '' : 's',
            $skipped,
            $dryRun ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Pick the right price source for a subscription. Prefers the
     * eager-loaded planPrice row (canonical), falls back to the
     * legacy price_monthly column on customer_products for older
     * rows that pre-date the plans v3 refactor.
     */
    private function resolvePrice(CustomerProduct $subscription): float
    {
        if ($subscription->planPrice !== null) {
            return (float) $subscription->planPrice->price;
        }

        return (float) ($subscription->price_monthly ?? 0);
    }

    private function resolveIntervalCount(CustomerProduct $subscription): int
    {
        if ($subscription->planPrice !== null) {
            return (int) $subscription->planPrice->interval_count;
        }

        return (int) ($subscription->interval_count ?? 1);
    }

    private function resolveIntervalUnit(CustomerProduct $subscription): string
    {
        if ($subscription->planPrice !== null) {
            return $subscription->planPrice->interval_unit;
        }

        return $subscription->interval_unit ?? 'month';
    }

    private function resolveIntervalLabel(CustomerProduct $subscription): string
    {
        if ($subscription->planPrice !== null) {
            // ProductPlanPrice exposes interval_label as an accessor.
            return $subscription->planPrice->interval_label;
        }

        return $subscription->interval_label;
    }

    private function buildLineDescription(CustomerProduct $subscription, string $intervalLabel): string
    {
        $product = $subscription->product->name;
        // plan_id is nullable so productPlan may not be eager-loadable;
        // fall back to the legacy plan column then to a generic
        // "Subscription" label.
        if ($subscription->productPlan) {
            $plan = $subscription->productPlan->name;
        } else {
            $plan = $subscription->plan ?? 'Subscription';
        }

        return sprintf('%s — %s (%s)', $product, $plan, $intervalLabel);
    }

    private function advance(Carbon $from, int $count, string $unit): Carbon
    {
        $count = max(1, $count);

        return match ($unit) {
            'day' => $from->copy()->addDays($count),
            'week' => $from->copy()->addWeeks($count),
            'month' => $from->copy()->addMonthsNoOverflow($count),
            'year' => $from->copy()->addYearsNoOverflow($count),
            default => $from->copy()->addMonthNoOverflow(),
        };
    }
}
