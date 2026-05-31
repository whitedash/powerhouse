<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsiteDiskWarning;
use App\Services\CpanelService;
use Illuminate\Console\Command;

/**
 * Pull cPanel usage (disk / email / bandwidth) for every active site with
 * credentials, and raise a disk warning to super_admins at 80% / critical
 * at 90%. One failing site never aborts the sweep — errors are reported
 * and the loop continues. A 1s pause between sites keeps us friendly to
 * the shared cPanel host.
 */
class SyncWebsiteHosting extends Command
{
    protected $signature = 'websites:sync-hosting {--website= : Sync a single website by ID}';

    protected $description = 'Refresh cPanel disk / email / bandwidth usage for managed websites.';

    public function handle(): int
    {
        $query = Website::where('status', 'active')
            ->whereNotNull('cpanel_username')
            ->whereNotNull('cpanel_token');

        if ($this->option('website')) {
            $query->where('id', (int) $this->option('website'));
        }

        $sites = $query->get();
        $this->info("Syncing {$sites->count()} site".($sites->count() === 1 ? '' : 's').'…');

        foreach ($sites as $site) {
            try {
                $data = app(CpanelService::class, ['website' => $site])->syncAll();
                $site->update($data);

                $percent = $site->disk_percent;
                if ($percent !== null && $percent >= 90) {
                    $this->createDiskAlert($site, 'critical');
                } elseif ($percent !== null && $percent >= 80) {
                    $this->createDiskAlert($site, 'warning');
                }

                $this->info('✓ '.$site->url.' — disk: '.($percent ?? '?').'%');
            } catch (\Throwable $e) {
                $this->error('✗ '.$site->url.': '.$e->getMessage());
            }

            // Rate limit: gentle pause between accounts on the shared host.
            sleep(1);
        }

        return self::SUCCESS;
    }

    private function createDiskAlert(Website $site, string $level): void
    {
        User::where('role', 'super_admin')->each(
            fn (User $u) => $u->notify(new WebsiteDiskWarning($site, $level))
        );
    }
}
