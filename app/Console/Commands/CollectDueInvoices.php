<?php

namespace App\Console\Commands;

use App\Services\OffSessionCollector;
use Illuminate\Console\Command;

/**
 * Billing P2 — off-session collection of due invoices.
 *
 * Thin wrapper: all money logic lives in OffSessionCollector. For each customer
 * with auto_collect = true and an active default card, charges the outstanding
 * balance of each collectible invoice off-session, recording every attempt in
 * the payments ledger and alerting on every failure.
 *
 * Safe rollout:
 *   --dry-run            list what WOULD be sent + charged, charging nothing.
 *   --customer={id}      aim a real run at a single customer.
 *   --invoice={id}       aim a real run at a single invoice.
 */
class CollectDueInvoices extends Command
{
    /** @var string */
    protected $signature = 'invoices:collect-due '
        .'{--dry-run : List which customers/invoices would be sent + charged, charging nothing}'
        .'{--customer= : Restrict to a single customer id}'
        .'{--invoice= : Restrict to a single invoice id}';

    /** @var string */
    protected $description = 'Charge auto-collect customers off-session for their due invoices (Billing P2).';

    public function handle(OffSessionCollector $collector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyCustomer = $this->option('customer') !== null ? (int) $this->option('customer') : null;
        $onlyInvoice = $this->option('invoice') !== null ? (int) $this->option('invoice') : null;

        if ($dryRun) {
            $this->warn('DRY RUN — no charges will be made.');
        }

        $results = $collector->run($dryRun, $onlyCustomer, $onlyInvoice);

        if ($results === []) {
            $this->info('No auto-collect invoices to process.');

            return self::SUCCESS;
        }

        $rows = array_map(fn (array $r): array => [
            $r['invoice'],
            $r['customer'],
            '£'.number_format((float) $r['amount'], 2),
            $r['status'],
            $r['reason'],
        ], $results);

        $this->table(['Invoice', 'Company', 'Amount', 'Outcome', 'Detail'], $rows);

        // Per-outcome tally for the run log.
        $tally = [];
        foreach ($results as $r) {
            $tally[$r['status']] = ($tally[$r['status']] ?? 0) + 1;
        }
        $summary = implode(', ', array_map(fn ($k, $v): string => "{$v} {$k}", array_keys($tally), $tally));
        $this->info(($dryRun ? '[dry-run] ' : '').'Processed '.count($results).' invoice(s): '.$summary.'.');

        return self::SUCCESS;
    }
}
