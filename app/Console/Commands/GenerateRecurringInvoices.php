<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Daily sweep that clones any recurring invoice whose
 * recurring_next_date has come due into a fresh draft child, copies
 * every line over, and rolls the parent's next_date forward by one
 * interval.
 *
 * Idempotent in practice: we move the parent's recurring_next_date
 * forward inside the same transaction, so a re-run on the same day
 * won't double-bill.
 */
class GenerateRecurringInvoices extends Command
{
    /** @var string */
    protected $signature = 'invoices:generate-recurring '
        .'{--dry-run : Show which invoices would be generated without inserting anything}';

    /** @var string */
    protected $description = 'Clone due recurring invoices into draft children and advance their schedules.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();

        // Recurring templates that are due, still in a billable status,
        // and haven't reached their optional end date. Status filter
        // excludes draft + void — a draft template would mean the
        // operator hasn't sent the first cycle yet.
        $due = Invoice::where('is_recurring', true)
            ->whereIn('status', ['sent', 'paid', 'overdue'])
            ->whereNotNull('recurring_next_date')
            ->where('recurring_next_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('recurring_ends_at')
                    ->orWhere('recurring_ends_at', '>=', $today);
            })
            ->with(['lines' => fn ($q) => $q->orderBy('sort_order')])
            ->get();

        if ($due->isEmpty()) {
            $this->info('No recurring invoices due today.');

            return self::SUCCESS;
        }

        $generated = 0;
        $skipped = 0;

        foreach ($due as $template) {
            try {
                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] Would clone %s (customer #%d) — next: %s',
                        $template->number,
                        $template->customer_id,
                        $template->recurring_next_date?->toDateString() ?? '—',
                    ));
                    $generated++;

                    continue;
                }

                $child = DB::transaction(function () use ($template, $today): Invoice {
                    // Match the existing INV-#### scheme. Lock for
                    // update inside the txn so a concurrent store()
                    // can't grab the same number.
                    $number = $this->nextInvoiceNumberLocked();

                    // Due date matches the template's payment terms
                    // — we compute it from the original due − issue
                    // gap so "Net 14 on day 1, Net 7 on day 2" stays
                    // consistent rather than re-deriving from terms.
                    $termDays = (int) max(
                        0,
                        $template->issue_date && $template->due_date
                            ? $template->issue_date->diffInDays($template->due_date)
                            : 14,
                    );

                    $child = Invoice::create([
                        'number' => $number,
                        'customer_id' => $template->customer_id,
                        'billing_entity_id' => $template->billing_entity_id,
                        'type' => $template->type,
                        'status' => 'draft',
                        'subtotal' => $template->subtotal,
                        'vat_rate' => $template->vat_rate,
                        'vat_amount' => $template->vat_amount,
                        'total' => $template->total,
                        'amount_paid' => 0,
                        'issue_date' => $today,
                        'due_date' => $today->copy()->addDays($termDays),
                        'notes' => $template->notes,
                        'created_by' => $template->created_by,
                        'parent_invoice_id' => $template->id,
                        // Children are one-off bills, not new templates.
                        // The original carries the recurring schedule.
                        'is_recurring' => false,
                    ]);

                    foreach ($template->lines as $line) {
                        InvoiceLine::create([
                            'invoice_id' => $child->id,
                            'product_id' => $line->product_id,
                            'plan_id' => $line->plan_id,
                            'description' => $line->description,
                            'note' => $line->note,
                            'quantity' => $line->quantity,
                            'unit_price' => $line->unit_price,
                            'amount' => $line->amount,
                            'sort_order' => $line->sort_order,
                        ]);
                    }

                    // Roll the template's next_date forward by one
                    // cycle. If the end date is past, this also
                    // marks it done implicitly — the next pass'
                    // where-clause filters that row out.
                    $next = $this->advance(
                        $template->recurring_next_date ?? $today,
                        (int) $template->recurring_interval_count,
                        (string) $template->recurring_interval_unit,
                    );
                    $template->update(['recurring_next_date' => $next]);

                    ActivityLog::create([
                        // No request context here — system events
                        // carry a null user_id, which the audit
                        // log surface already renders as "System".
                        'user_id' => null,
                        'user_role' => 'system',
                        'action' => 'invoice.recurring_generated',
                        'entity_type' => 'invoice',
                        'entity_id' => $child->id,
                        'before' => null,
                        'after' => [
                            'parent_id' => $template->id,
                            'parent_number' => $template->number,
                            'new_invoice_id' => $child->id,
                            'new_invoice_number' => $child->number,
                        ],
                        'ip_address' => null,
                        'user_agent' => 'artisan:invoices:generate-recurring',
                    ]);

                    return $child;
                });

                $this->info(sprintf(
                    'Generated %s from template %s (next: %s)',
                    $child->number,
                    $template->number,
                    $template->fresh()?->recurring_next_date?->toDateString() ?? '—',
                ));
                $generated++;
            } catch (Throwable $e) {
                $this->error(sprintf(
                    'Skipped %s — %s',
                    $template->number,
                    $e->getMessage(),
                ));
                $skipped++;
            }
        }

        $this->info(sprintf(
            'Done. %d generated, %d skipped.%s',
            $generated,
            $skipped,
            $dryRun ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Mirrors InvoiceController::generateNextInvoiceNumberLocked() so
     * the scheme stays in lockstep. Kept here rather than calling into
     * the HTTP controller to avoid coupling a CLI job to that layer.
     */
    private function nextInvoiceNumberLocked(): string
    {
        $last = Invoice::where('number', 'like', 'INV-%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return 'INV-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function advance(Carbon $from, int $count, string $unit): Carbon
    {
        return match ($unit) {
            'week' => $from->copy()->addWeeks($count),
            'month' => $from->copy()->addMonthsNoOverflow($count),
            'year' => $from->copy()->addYearsNoOverflow($count),
            default => $from->copy()->addMonth(),
        };
    }
}
