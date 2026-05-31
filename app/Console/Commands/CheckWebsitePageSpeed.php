<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\PageSpeedService;
use Illuminate\Console\Command;

/**
 * Run Google PageSpeed against active sites. By default only sites not
 * checked in the last 7 days are processed (so the weekly schedule
 * spreads load); --force checks everything matched. --website targets a
 * single site. Failures are reported per-site and don't abort the run.
 */
class CheckWebsitePageSpeed extends Command
{
    protected $signature = 'websites:check-pagespeed {--website= : Check a single website by ID} {--force : Skip the 7-day freshness gate}';

    protected $description = 'Run Google PageSpeed Insights for managed websites.';

    public function handle(): int
    {
        $query = Website::where('status', 'active')
            ->when(! $this->option('force'), fn ($q) => $q->where(function ($q2): void {
                $q2->whereNull('pagespeed_checked_at')
                    ->orWhere('pagespeed_checked_at', '<', now()->subDays(7));
            }));

        if ($this->option('website')) {
            $query->where('id', (int) $this->option('website'));
        }

        $sites = $query->get();
        $this->info('Checking '.$sites->count().' site'.($sites->count() === 1 ? '' : 's').'…');

        foreach ($sites as $site) {
            try {
                $data = app(PageSpeedService::class)->check($site);
                $site->update($data);

                $grade = match (true) {
                    $data['pagespeed_mobile'] >= 90 => '✅',
                    $data['pagespeed_mobile'] >= 50 => '⚠️',
                    default => '🔴',
                };

                $this->info($grade.' '.$site->url.' — Mobile: '.$data['pagespeed_mobile'].'/100 Desktop: '.$data['pagespeed_desktop'].'/100');
            } catch (\Throwable $e) {
                $this->error('✗ '.$site->url.': '.$e->getMessage());
            }

            // Rate limit: stagger to stay well within the 25k/day quota.
            sleep(2);
        }

        return self::SUCCESS;
    }
}
