<?php

namespace App\Console\Commands;

use App\Http\Controllers\Internal\DomainController;
use App\Models\Domain;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily sweep that pulls the latest Cloudflare zone + SSL state for
 * every managed domain (or falls back to a plain dns_get_record()
 * lookup for non-Cloudflare ones), then writes the computed
 * status / ssl_status / last_synced_at back to the row so the
 * dashboard and the Domains index don't have to re-query Cloudflare
 * on every page load.
 *
 * The actual refresh logic lives on DomainController::refreshDomainHealth
 * so the manual "Check now" button on the UI and this cron share one
 * code path.
 */
class CheckDomainHealth extends Command
{
    /** @var string */
    protected $signature = 'domains:check-health';

    /** @var string */
    protected $description = 'Refresh status + SSL expiry for every managed domain.';

    public function handle(DomainController $domains): int
    {
        // Only domains where we have something to sync — either
        // a Cloudflare zone or at least an expiry_date the status
        // recomputation can use. Skipping the rest keeps the
        // sweep tight.
        $rows = Domain::query()
            ->where(function ($q) {
                $q->whereNotNull('expiry_date')
                    ->orWhereNotNull('cloudflare_zone_id');
            })
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No domains to check.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($rows as $domain) {
            try {
                $domains->refreshDomainHealth($domain);

                if ($domain->status === 'expiring_soon') {
                    $days = $domain->expiry_date
                        ? (int) abs(now()->diffInDays($domain->expiry_date))
                        : 0;
                    $this->warn(sprintf('⚠ Expiring: %s in %d days', $domain->domain, $days));
                } elseif ($domain->status === 'expired') {
                    $this->error(sprintf('✗ Expired: %s', $domain->domain));
                } else {
                    $this->line(sprintf('✓ %s — %s / SSL %s', $domain->domain, $domain->status, $domain->ssl_status));
                }
                $ok++;
            } catch (Throwable $e) {
                $this->error(sprintf('Failed to check %s — %s', $domain->domain, $e->getMessage()));
                $failed++;
            }
        }

        $this->info(sprintf('Done. %d checked, %d failed.', $ok, $failed));

        return self::SUCCESS;
    }
}
