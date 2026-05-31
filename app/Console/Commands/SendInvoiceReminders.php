<?php

namespace App\Console\Commands;

use App\Mail\InvoiceReminder;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Services\ReminderTemplateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendInvoiceReminders extends Command
{
    protected $signature = 'invoices:send-reminders';

    protected $description = 'Send automated payment reminders for overdue and outstanding invoices.';

    /**
     * Tier matrix — keyed on `days_overdue` (negative = days until due,
     * positive = days past due). Each tier dictates the next-reminder
     * cadence after firing.
     *
     * Exposed as a public constant so the manual-reminder controller
     * path can resolve the same tier the automated sweep would.
     *
     * @var array<string, array{min: int, next_in_days: int|null}>
     */
    public const TIERS = [
        // days_overdue >= 30 → final notice, next nudge in 30 days
        'final_notice' => ['min' => 30, 'next_in_days' => 30],
        'second_reminder' => ['min' => 14, 'next_in_days' => 14],
        'first_reminder' => ['min' => 1, 'next_in_days' => 7],
        'due_today' => ['min' => -1, 'next_in_days' => 3],
        'due_soon' => ['min' => -3, 'next_in_days' => null], // next = due_date
    ];

    public function handle(ReminderTemplateService $templates): int
    {
        $now = now();

        // Step 1: flip any sent invoices whose due_date has passed to
        // overdue. The nav badge counts depend on this and the dashboard
        // attention card surfaces them.
        $flipped = Invoice::where('status', 'sent')
            ->whereNotNull('due_date')
            ->where('due_date', '<', $now->copy()->startOfDay())
            ->get();

        foreach ($flipped as $inv) {
            $inv->update(['status' => 'overdue']);
        }

        if ($flipped->isNotEmpty()) {
            Cache::forget('nav.invoices_overdue');
            Cache::forget('nav.invoices_outstanding');
            $this->info("Flipped {$flipped->count()} sent → overdue.");
        }

        // Step 2: pick up the invoices due for a nudge. The (next_reminder_at,
        // reminders_paused, status) compound index covers this scan.
        $invoices = Invoice::whereIn('status', ['sent', 'overdue'])
            ->where('reminders_paused', false)
            ->where(function ($q) use ($now) {
                $q->whereNull('next_reminder_at')
                    ->orWhere('next_reminder_at', '<=', $now);
            })
            ->with(['customer.primaryContact', 'billingEntity'])
            ->get();

        $processed = 0;

        foreach ($invoices as $invoice) {
            if (! $invoice->due_date) {
                continue;
            }

            $daysOverdue = (int) $now->copy()->startOfDay()
                ->diffInDays($invoice->due_date->copy()->startOfDay(), false);
            // diffInDays(date, false) returns negative when the argument is
            // in the future. Flip the sign so "overdue" reads as positive.
            $daysOverdue = -$daysOverdue;

            $tier = $this->resolveTier($daysOverdue);
            if ($tier === null) {
                continue;
            }

            $contact = $invoice->customer->primaryContact;
            $recipient = $contact ? $contact->email : 'unknown';

            // Resolve and render the template OUTSIDE the transaction
            // so a missing/inactive template doesn't roll back the
            // reminder count + next_reminder_at update. The reminder
            // is still "logged"; the body just falls back to a tier-
            // shaped placeholder.
            $template = $templates->getTemplateForTier($tier);
            $rendered = $template !== null
                ? $templates->renderTemplate($template, $invoice)
                : null;

            DB::transaction(function () use ($invoice, $tier, $daysOverdue, $now, $recipient, $rendered) {
                $invoice->increment('reminder_count');

                $nextAt = self::TIERS[$tier]['next_in_days'] === null
                    ? $invoice->due_date->copy()->startOfDay()
                    : $now->copy()->addDays(self::TIERS[$tier]['next_in_days']);

                $invoice->update([
                    'last_reminder_sent_at' => $now,
                    'next_reminder_at' => $nextAt,
                ]);

                ActivityLog::create([
                    'user_id' => null,
                    'user_role' => 'system',
                    'action' => 'invoice.reminder_sent',
                    'entity_type' => 'invoice',
                    'entity_id' => $invoice->id,
                    'after' => [
                        'tier' => $tier,
                        'days_overdue' => $daysOverdue,
                        'reminder_count' => $invoice->reminder_count,
                        'recipient' => $recipient,
                        'method' => 'automated',
                        // Capture the rendered subject + body preview
                        // so the audit log lets staff see exactly what
                        // would be (or was, once email is live) sent.
                        'subject' => $rendered['subject'] ?? null,
                        'body_preview' => $rendered !== null
                            ? Str::limit($rendered['body'], 200)
                            : null,
                        'email_ready' => $rendered !== null,
                    ],
                    'ip_address' => null,
                    'user_agent' => 'invoices:send-reminders',
                ]);

            });

            // Deliver the email outside the transaction so a Postmark
            // failure can't roll back the logged reminder + cadence bump.
            // Requires a resolved template and a real contact email.
            if ($template !== null && $contact && $contact->email) {
                Mail::to($contact->email)->send(new InvoiceReminder($invoice, $template));
            }

            $this->info("Reminder sent: {$invoice->number} ({$tier})");
            $processed++;
        }

        $this->info("Done. {$processed} reminder".($processed === 1 ? '' : 's').' processed.');

        return self::SUCCESS;
    }

    public static function resolveTierFor(int $daysOverdue): ?string
    {
        foreach (self::TIERS as $tier => $config) {
            if ($daysOverdue >= $config['min']) {
                return $tier;
            }
        }

        return null;
    }

    private function resolveTier(int $daysOverdue): ?string
    {
        // First tier whose `min` is <= daysOverdue wins. The TIERS array
        // is ordered descending by `min` so iteration short-circuits at
        // the right boundary.
        foreach (self::TIERS as $tier => $config) {
            if ($daysOverdue >= $config['min']) {
                return $tier;
            }
        }

        return null;
    }
}
