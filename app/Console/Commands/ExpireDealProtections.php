<?php

namespace App\Console\Commands;

use App\Enums\ReferralStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Expires lapsed deal-registration protection windows.
 *
 * A deal is expired when its 90-day protection (opened at approval) has
 * elapsed AND the deal never closed — i.e. it's still approved, past its
 * protected_until, not won, and not yet converted to a customer.
 *
 * Deliberately NOT touched:
 *   - won / converted (customer_id set) deals — the referrer earned them;
 *   - pending_review / rejected / expired deals;
 *   - plain leads (referral_status NULL).
 *
 * Idempotent: once a row flips to `expired` it no longer matches the
 * selection, so re-running is a clean no-op. protected_until is left as
 * the historical date for the audit trail.
 */
class ExpireDealProtections extends Command
{
    /** @var string */
    protected $signature = 'referrals:expire-protections '
        .'{--dry-run : List the deals that would expire without changing anything}';

    /** @var string */
    protected $description = 'Expire approved deal registrations whose 90-day protection window has lapsed without closing.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        $query = Lead::query()
            ->where('referral_status', ReferralStatus::Approved->value)
            ->where('protected_until', '<', $now)
            // Did NOT close: not won AND not converted to a customer.
            ->where('status', '!=', 'won')
            ->whereNull('customer_id');

        $count = 0;

        // chunkById keeps memory flat and is stable under concurrent writes
        // (each row only matches once, since the update removes it from the
        // selection — so re-running mid-flight can't double-process).
        $query->chunkById(200, function ($leads) use (&$count, $dryRun): void {
            foreach ($leads as $lead) {
                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] Would expire #%d — protected until %s',
                        $lead->id,
                        $lead->protected_until?->toDateString() ?? 'unknown',
                    ));
                    $count++;

                    continue;
                }

                try {
                    DB::transaction(function () use ($lead): void {
                        $lead->update(['referral_status' => ReferralStatus::Expired]);

                        ActivityLog::create([
                            'user_id' => null,
                            'user_role' => 'system',
                            'action' => 'referral.deal_expired',
                            'entity_type' => Lead::class,
                            'entity_id' => $lead->id,
                            'before' => ['referral_status' => ReferralStatus::Approved->value],
                            'after' => [
                                'referral_status' => ReferralStatus::Expired->value,
                                // Historical window end, preserved as-is.
                                'protected_until' => $lead->protected_until?->toIso8601String(),
                            ],
                            'ip_address' => null,
                            'user_agent' => 'artisan:referrals:expire-protections',
                        ]);
                    });

                    $this->info('Expired: #'.$lead->id);
                    $count++;
                } catch (Throwable $e) {
                    $this->error(sprintf('Failed to expire #%d: %s', $lead->id, $e->getMessage()));
                }
            }
        });

        $this->info(sprintf(
            '%d deal%s expired.%s',
            $count,
            $count === 1 ? '' : 's',
            $dryRun ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }
}
