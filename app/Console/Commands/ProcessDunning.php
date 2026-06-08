<?php

namespace App\Console\Commands;

use App\Services\DunningService;
use Illuminate\Console\Command;

/**
 * Billing P3 — dunning for failed off-session collections.
 *
 * Thin wrapper: all money logic lives in DunningService. Finds invoices with
 * ≥1 failed/requires_action attempt that are due for the next retry (cadence
 * anchored to the first failure), retries them with a per-attempt idempotency
 * key, emails the customer on every failed attempt, and escalates to the
 * existing suspend backbone once retries are exhausted.
 *
 * Safe rollout:
 *   --dry-run        list who'd be retried/relinked/escalated, doing nothing.
 *   --customer={id}  restrict to one customer.
 *   --invoice={id}   restrict to one invoice.
 */
class ProcessDunning extends Command
{
    /** @var string */
    protected $signature = 'billing:process-dunning '
        .'{--dry-run : List what would be retried/emailed/escalated, charging + sending nothing}'
        .'{--customer= : Restrict to a single customer id}'
        .'{--invoice= : Restrict to a single invoice id}';

    /** @var string */
    protected $description = 'Retry failed off-session collections on a cadence and escalate exhausted invoices (Billing P3).';

    public function handle(DunningService $dunning): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyCustomer = $this->option('customer') !== null ? (int) $this->option('customer') : null;
        $onlyInvoice = $this->option('invoice') !== null ? (int) $this->option('invoice') : null;

        if ($dryRun) {
            $this->warn('DRY RUN — no charges, emails, or records will be made.');
        }

        $results = $dunning->run($dryRun, $onlyCustomer, $onlyInvoice);

        if ($results === []) {
            $this->info('No invoices in active dunning.');

            return self::SUCCESS;
        }

        $rows = array_map(fn (array $r): array => [
            $r['invoice'],
            $r['customer'],
            $r['attempt'].'/'.$r['max_attempts'],
            $r['status'],
            $r['reason'],
        ], $results);

        $this->table(['Invoice', 'Customer', 'Attempt', 'Outcome', 'Detail'], $rows);

        $tally = [];
        foreach ($results as $r) {
            $tally[$r['status']] = ($tally[$r['status']] ?? 0) + 1;
        }
        $summary = implode(', ', array_map(fn ($k, $v): string => "{$v} {$k}", array_keys($tally), $tally));
        $this->info(($dryRun ? '[dry-run] ' : '').'Processed '.count($results).' invoice(s): '.$summary.'.');

        return self::SUCCESS;
    }
}
