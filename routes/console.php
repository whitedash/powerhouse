<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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

// Auto-close support tickets idle in awaiting_customer for longer
// than the configured threshold (Settings → Notifications). Runs
// in the small hours so any morning team activity wins the
// staleness race.
Schedule::command('support:close-inactive')
    ->dailyAt('03:00')
    ->timezone('Europe/London')
    ->withoutOverlapping()
    ->runInBackground();
