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
