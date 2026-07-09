<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plans widget "intro price then full price" sweep. Flips a subscription off
 * its intro price onto the full price once the stored swap date arrives.
 *
 * An intro purchase provisions a customer_product on the intro price row with
 * intro_swap_at = now + N days (computed at purchase) and a snapshotted
 * intro_swap_price_id (the promised full price). This command finds rows whose
 * swap date is due, points plan_price_id at the full price, syncs the CP's
 * cadence to it, and clears the intro-schedule fields.
 *
 * It leaves next_billing_date ALONE: provisioning set it equal to the swap
 * date, so the moment this command flips the price the very next
 * invoices:generate-subscriptions run bills the (now full) price for the
 * period starting today and rolls the date forward — the sweep reads
 * planPrice->{price,interval_*}, so it needs zero changes. Scheduling this
 * BEFORE that sweep (routes/console.php) makes the first full charge land on
 * the swap date rather than a day late.
 */
class ApplyIntroPriceSwaps extends Command
{
    /** @var string */
    protected $signature = 'plans:apply-intro-price-swaps '
        .'{--dry-run : List which subscriptions would swap without changing any rows}';

    /** @var string */
    protected $description = 'Swap Plans-widget subscriptions off their intro price onto the full price when the intro window ends.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();

        // Only active subscriptions swap — matching the subscription sweep,
        // which only bills active rows. A 'pending' (manual-review) intro
        // defers its swap until approval flips it active, at which point a
        // later run catches the (now past-due) swap date.
        $due = CustomerProduct::where('status', 'active')
            ->whereNotNull('intro_swap_at')
            ->where('intro_swap_at', '<=', $today->toDateString())
            ->with(['introSwapPrice:id,price,interval_count,interval_unit'])
            ->get();

        if ($due->isEmpty()) {
            $this->info('No intro-price subscriptions due to swap today.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d intro-price subscription(s) due to swap.', $due->count()));

        $swapped = 0;
        $skipped = 0;

        foreach ($due as $subscription) {
            $target = $subscription->introSwapPrice;

            // The snapshotted target price was SET NULL by a catalog deletion:
            // we can't swap to a price that no longer exists. Surface it loudly
            // and leave the row untouched (it stays flagged for the operator)
            // rather than silently stranding the customer on the intro price.
            if ($target === null) {
                $this->error(sprintf(
                    'Skipped subscription #%d — its intro swap-target price no longer exists.',
                    $subscription->id,
                ));
                Log::error('plans.intro_swap_target_missing', [
                    'customer_product_id' => $subscription->id,
                ]);
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] Would swap subscription #%d → price #%d (£%s / %s).',
                    $subscription->id,
                    $target->id,
                    number_format((float) $target->price, 2),
                    $target->interval_label,
                ));
                $swapped++;

                continue;
            }

            try {
                DB::transaction(function () use ($subscription, $target): void {
                    // Point at the full price and sync the CP's own cadence
                    // columns to it. The sweep reads planPrice->interval_*
                    // (these CP columns are only its legacy fallback), but
                    // keeping them in step leaves the row self-consistent for
                    // any other reader (MRR, display).
                    $subscription->update([
                        'plan_price_id' => $target->id,
                        'interval_count' => $target->interval_count,
                        'interval_unit' => $target->interval_unit,
                        // One-shot swap: clear the schedule so it never re-fires.
                        // next_billing_date is deliberately left as-is (== the
                        // swap date) for the subscription sweep to pick up.
                        'intro_swap_at' => null,
                        'intro_swap_price_id' => null,
                    ]);

                    ActivityLog::create([
                        'user_id' => null,
                        'user_role' => 'system',
                        'action' => 'customer_product.intro_price_swapped',
                        'entity_type' => 'customer_product',
                        'entity_id' => $subscription->id,
                        'before' => null,
                        'after' => [
                            'to_plan_price_id' => $target->id,
                            'price' => (float) $target->price,
                        ],
                        'ip_address' => null,
                        'user_agent' => 'artisan:plans:apply-intro-price-swaps',
                    ]);
                });

                // MRR (dashboard, cached 2 min) moves from the intro amount to
                // the full price the instant this lands — bust it like the
                // subscription sweep does after billing.
                Cache::forget('dash.mrr');

                $this->info(sprintf(
                    'Swapped subscription #%d onto price #%d (£%s).',
                    $subscription->id,
                    $target->id,
                    number_format((float) $target->price, 2),
                ));
                $swapped++;
            } catch (Throwable $e) {
                $this->error(sprintf('Failed subscription #%d: %s', $subscription->id, $e->getMessage()));
                Log::error('plans.intro_swap_failed', [
                    'customer_product_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        $this->info(sprintf(
            '%d swapped, %d skipped.%s',
            $swapped,
            $skipped,
            $dryRun ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }
}
