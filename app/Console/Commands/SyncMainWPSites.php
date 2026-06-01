<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsitePluginsOutdated;
use App\Services\MainWPService;
use Illuminate\Console\Command;

/**
 * Pull WordPress telemetry (WP/PHP version, plugin/theme update counts,
 * last backup) from the MainWP dashboard into the websites table. Matches
 * MainWP child sites to Powerhouse websites by stored mainwp_site_id first,
 * then by domain. Raises a plugin-update alert to super_admins per site
 * with outstanding updates. One failing site never aborts the sweep.
 *
 * Graceful no-op when MainWP isn't configured, so the daily schedule and
 * local dev both run cleanly without credentials.
 */
class SyncMainWPSites extends Command
{
    protected $signature = 'websites:sync-wordpress {--website= : Sync a specific website by ID} {--force : Re-sync even if synced recently}';

    protected $description = 'Sync WordPress site data from MainWP into managed websites.';

    public function handle(): int
    {
        if (! config('services.mainwp.enabled')) {
            $this->info('MainWP not configured.');

            return self::SUCCESS;
        }

        $mainwp = app(MainWPService::class);

        try {
            $sites = $mainwp->getSites();
        } catch (\Throwable $e) {
            $this->error('Could not reach MainWP: '.$e->getMessage());

            return self::FAILURE;
        }

        $websites = Website::when(
            $this->option('website'),
            fn ($q) => $q->where('id', (int) $this->option('website')),
        )
            ->where('status', 'active')
            ->get();

        foreach ($sites as $site) {
            $domain = parse_url($site['url'] ?? '', PHP_URL_HOST);

            // Prefer an explicit mainwp_site_id link; fall back to a domain
            // substring match for sites that haven't been linked yet.
            $website = $websites->first(
                fn (Website $w): bool => ($w->mainwp_site_id !== null && (int) $w->mainwp_site_id === (int) ($site['id'] ?? 0))
                    || ($domain !== null && $domain !== '' && str_contains($w->url ?? '', $domain)),
            );

            if (! $website) {
                $this->line('  No match: '.($site['url'] ?? 'unknown'));

                continue;
            }

            try {
                $data = $mainwp->mapSiteData($site);
                $website->update($data);

                if ($data['plugins_outdated'] > 0) {
                    $this->createPluginAlert($website, $data['plugins_outdated']);
                }

                $this->info('  ✓ '.$website->url.' — WP '.($data['wp_version'] ?? '?').', '.$data['plugins_outdated'].' updates');
            } catch (\Throwable $e) {
                $this->error('  ✗ '.$website->url.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function createPluginAlert(Website $website, int $count): void
    {
        User::where('role', 'super_admin')->each(
            fn (User $u) => $u->notify(new WebsitePluginsOutdated($website, $count)),
        );
    }
}
