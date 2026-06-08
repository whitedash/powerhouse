<?php

use App\Http\Controllers\Internal\DashboardController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automated invoice reminders — fire once a day in business hours.
// withoutOverlapping() guards against the previous run still being in
// flight; runInBackground() releases the scheduler tick so the rest of
// the schedule doesn't queue behind a slow command.
Schedule::command('invoices:send-reminders')
    ->dailyAt('09:00')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Daily sweep that clones due recurring-invoice templates into draft
// children. Runs earlier than the reminder pass so brand-new drafts
// are already visible by the time the morning team logs in.
Schedule::command('invoices:generate-recurring')
    ->dailyAt('07:00')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Auto-generate draft invoices for active subscriptions whose
// next_billing_date has come due. Runs 30 minutes after the
// recurring-invoice generator so the two sweeps don't compete for
// the INV-#### lock — both call Invoice::generateNextNumber()
// which is itself safe, but staggering keeps log output legible.
Schedule::command('invoices:generate-subscriptions')
    ->dailyAt('07:30')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Per-website hosting billing (Stage 1b). Hosting lives on the website
// (plan_id/plan_price_id), billed on its own hosting_next_billing_date — NOT
// via a CustomerProduct. Slotted between the subscription + domain sweeps;
// idempotent via the in-txn advance of hosting_next_billing_date.
Schedule::command('invoices:generate-hosting')
    ->dailyAt('07:45')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Expiry-triggered domain renewals: 14 days before a domain's expiry,
// raise a draft renewal invoice (auto-renewing + priced domains only).
// Domains are NOT CustomerProducts, so the subscription sweep above never
// double-bills them. Idempotent via domains.renewal_invoiced_for.
Schedule::command('invoices:generate-domain-renewals')
    ->dailyAt('08:00')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Off-session collection (Billing P2). Slots in AFTER all invoice generation
// (last generator 08:00) and BEFORE reminders (09:00), so a just-collected
// invoice is already marked paid and won't trigger a reminder. auto_collect is
// the per-customer master gate; every attempt is recorded in the ledger.
Schedule::command('invoices:collect-due')
    ->dailyAt('08:30')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Dunning (Billing P3) — retry failed off-session collections on a cadence,
// email the customer on every failed attempt, and escalate exhausted invoices
// into the existing suspend backbone. Slots in AFTER collect-due (08:30) and
// BEFORE reminders (09:00), so a recovered invoice is paid before reminders run
// and an in-dunning invoice is excluded from the generic reminder.
Schedule::command('billing:process-dunning')
    ->dailyAt('08:45')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Auto-close support tickets idle in awaiting_customer for longer
// than the configured threshold (Settings → Notifications). Runs
// in the small hours so any morning team activity wins the
// staleness race.
Schedule::command('support:close-inactive')
    ->dailyAt('03:00')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Expire lapsed deal-registration protections: approved deals past their
// 90-day protected_until that never closed flip to `expired`. Idempotent,
// so a single small-hours run (after the close-inactive sweep) suffices —
// won/converted deals are left untouched (earned).
Schedule::command('referrals:expire-protections')
    ->dailyAt('03:30')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Auto-suspend customer products with invoices overdue beyond the
// configured threshold (Settings → Billing). Runs after the 09:00
// reminder sweep so a final-notice sent this morning is already on
// record before the suspension gate checks for it.
Schedule::command('invoices:process-suspensions')
    ->dailyAt('10:00')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Re-attempt failed product webhooks whose backoff window has elapsed.
// The retry policy itself lives in WebhookDispatcher; this drives cadence.
Schedule::command('webhooks:retry-failed')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Refresh cPanel usage (disk/email/bandwidth) for managed websites and
// raise disk warnings. Every 6 hours so the dashboard disk KPIs stay
// reasonably fresh without hammering the shared host.
Schedule::command('websites:sync-hosting')
    ->everySixHours()
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Weekly PageSpeed sweep — Monday 06:00, ahead of the working week so
// fresh scores are on the dashboard when the team logs in.
Schedule::command('websites:check-pagespeed')
    ->weeklyOn(1, '06:00')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Daily WordPress telemetry sweep from MainWP — WP/PHP versions, plugin
// and theme update counts, last-backup timestamps. 07:00 so fresh update
// counts are on the dashboard before the team starts the day. No-ops
// cleanly when MainWP isn't configured.
Schedule::command('websites:sync-wordpress')
    ->dailyAt('07:00')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Refresh Cloudflare zone + SSL state and recompute domain status.
// Runs before the morning invoice + reminder sweeps so the
// dashboard "Domains expiring" KPI is current by 09:00.
Schedule::command('domains:check-health')
    ->dailyAt('06:00')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Time-based notification sweep: overdue projects (notify lead) and
// tasks due within 24h (notify assignee). Both checks are idempotent
// per day, so a single 08:00 run covers them — the spec's separate
// 08:00/09:00 split collapses safely into one command here.
Schedule::command('notifications:check-overdue')
    ->dailyAt('08:00')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();

// Warm the dashboard platform-health cache every 15 minutes so the
// landing page never has to wait on the outbound probes itself.
// Forget-then-build is intentional: we want to bust any stale row
// (eg. cleared at deploy) AND prime the new one in the same pass.
Schedule::call(function (): void {
    Cache::forget('dashboard.platform_health');
    app(DashboardController::class)->buildPlatformHealth();
})
    ->everyFifteenMinutes()
    ->timezone('Europe/London')
    ->name('warm.health.cache')
    ->withoutOverlapping();
