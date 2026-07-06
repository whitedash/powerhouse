<?php

namespace App\Console\Commands;

use App\Mail\PlanCheckoutAbandonedStaff;
use App\Models\PlanCheckoutAttempt;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Plans widget: flip stale pending checkout attempts to abandoned and
 * alert staff once per attempt.
 *
 * Window default is 24 HOURS, not minutes — a Stripe Checkout session
 * stays payable up to its 24h expiry, so anything shorter would flag
 * buyers who simply came back later (the settle path still overrides an
 * abandoned mark with completed if that happens, but the staff alert
 * would already have fired). At 24h "pending" means the session has
 * genuinely expired unpaid.
 *
 * Once-only alerting needs no extra column: the guarded per-row UPDATE
 * (WHERE status='pending') is the transition, and the mail sends only
 * when that transition actually happened — repeat runs skip non-pending
 * rows entirely.
 */
class ReconcileAbandonedCheckouts extends Command
{
    /** @var string */
    protected $signature = 'plans:reconcile-abandoned-checkouts '
        .'{--hours=24 : Age in hours before a pending attempt counts as abandoned}'
        .'{--dry-run : List what would be marked + mailed, changing nothing}';

    /** @var string */
    protected $description = 'Mark stale pending Plans-widget checkout attempts abandoned and alert staff (once per attempt).';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($hours);

        $stale = PlanCheckoutAttempt::where('status', 'pending')
            ->where('started_at', '<', $cutoff)
            ->with('planPrice.plan.product')
            ->orderBy('started_at')
            ->get();

        if ($stale->isEmpty()) {
            $this->info("No pending attempts older than {$hours}h.");

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be marked or mailed.');
        }

        // Same staff-inbox resolution as the support intake's staff alert:
        // support.notify_email, else the first super_admin's address.
        $staffEmail = config('support.notify_email')
            ?: User::where('role', 'super_admin')->orderBy('id')->value('email');

        $rows = [];
        foreach ($stale as $attempt) {
            $marked = false;
            if (! $dryRun) {
                // Guarded transition: only a still-pending row flips, so a
                // concurrent settle (or a second reconciler run) can never
                // double-mark — and the alert below fires exactly once.
                $marked = PlanCheckoutAttempt::whereKey($attempt->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'abandoned', 'abandoned_at' => now()]) === 1;

                if ($marked && $staffEmail !== null) {
                    try {
                        Mail::to($staffEmail)->send(new PlanCheckoutAbandonedStaff($attempt->fresh() ?? $attempt));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            $rows[] = [
                $attempt->id,
                $attempt->planPrice?->plan?->product?->name.' / '.$attempt->planPrice?->plan?->name,
                $attempt->purchaser_email,
                $attempt->started_at->diffForHumans(),
                $dryRun ? 'would mark' : ($marked ? 'abandoned + alerted' : 'skipped (raced)'),
            ];
        }

        $this->table(['Id', 'Plan', 'Email', 'Started', 'Outcome'], $rows);

        return self::SUCCESS;
    }
}
