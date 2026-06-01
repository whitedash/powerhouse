<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot production bring-up: guards the environment, runs migrations,
 * builds every cache, links storage, and ensures the session/queue tables
 * exist. Safe to re-run — every step is idempotent.
 */
class ProductionSetup extends Command
{
    protected $signature = 'setup:production
        {--skip-migrate : Skip database migration}
        {--skip-seed : Skip initial data seed}';

    protected $description = 'Prepare the application for production (migrate, cache, link, verify).';

    public function handle(): int
    {
        $this->info('Powerhouse Production Setup');
        $this->info('===========================');

        // 1. Environment guards — refuse to run anywhere but production.
        if (config('app.env') !== 'production') {
            $this->error('APP_ENV must be production. Current: '.config('app.env'));

            return self::FAILURE;
        }

        if (config('app.debug')) {
            $this->error('APP_DEBUG must be false in production.');

            return self::FAILURE;
        }

        $this->info('✓ Environment: production');
        $this->info('✓ Debug: false');

        // 2. Migrations.
        if (! $this->option('skip-migrate')) {
            $this->info('Running migrations...');
            $this->call('migrate', ['--force' => true]);
            $this->info('✓ Migrations complete');
        }

        // 3. Build all caches.
        $this->call('config:cache');
        $this->call('route:cache');
        $this->call('view:cache');
        $this->call('event:cache');
        $this->info('✓ Caches built');

        // 4. Storage symlink.
        if (! file_exists(public_path('storage'))) {
            $this->call('storage:link');
            $this->info('✓ Storage linked');
        } else {
            $this->info('✓ Storage already linked');
        }

        // 5. Sessions table.
        if (! Schema::hasTable('sessions')) {
            $this->call('make:session-table');
            $this->call('migrate', ['--force' => true]);
        }
        $this->info('✓ Sessions table ready');

        // 6. Queue tables.
        if (! Schema::hasTable('jobs')) {
            $this->call('make:queue-table');
            $this->call('migrate', ['--force' => true]);
        }
        $this->info('✓ Queue tables ready');

        // 7. Final optimize pass.
        $this->call('optimize');
        $this->info('✓ Optimized');

        $this->newLine();
        $this->info('✅ Production setup complete!');
        $this->newLine();
        $this->info('NEXT STEPS:');
        $this->line('1. Configure the queue worker (see below)');
        $this->line('2. Set up the cron job for scheduled tasks (see below)');
        $this->line('3. Run smoke test: php artisan smoke:test');
        $this->newLine();
        $this->info('QUEUE WORKER COMMAND:');
        $this->line('php artisan queue:work --queue=webhooks,default --sleep=3 --tries=3 --timeout=60 --max-time=3600');
        $this->newLine();
        $this->info('CRON JOB (add to cPanel):');
        $this->line('* * * * * php '.base_path('artisan').' schedule:run >> /dev/null 2>&1');

        return self::SUCCESS;
    }
}
