<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Post-deploy smoke test: exercises every critical subsystem (DB, mail,
 * queue, storage, cache, routing, payment + mail provider config) and
 * returns a non-zero exit code if anything fails, so it can gate go-live.
 */
class SmokeTest extends Command
{
    protected $signature = 'smoke:test';

    protected $description = 'Verify all critical platform functions after deployment.';

    public function handle(): int
    {
        $this->info('Smoke Test — Powerhouse');
        $this->info('=======================');

        $pass = 0;
        $fail = 0;

        $check = function (string $name, callable $test) use (&$pass, &$fail): void {
            try {
                if ($test()) {
                    $this->info('  ✅ '.$name);
                    $pass++;
                } else {
                    $this->error('  ❌ '.$name);
                    $fail++;
                }
            } catch (\Throwable $e) {
                $this->error('  ❌ '.$name.': '.$e->getMessage());
                $fail++;
            }
        };

        // --- Database --- (getPdo() opens the connection; it throws if it
        // can't, so reaching the return is itself the pass condition).
        $check('Database connection', function (): bool {
            DB::connection()->getPdo();

            return true;
        });

        // Compare migration files against the ran-log via the migrator API.
        // (Scraping migrate:status text is unsafe — a migration *named*
        // "...pending..." would trip a naive "Pending" string match.)
        $check('Migrations up to date', function (): bool {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return false;
            }

            $paths = array_merge([database_path('migrations')], $migrator->paths());
            $files = $migrator->getMigrationFiles($paths);
            $ran = $migrator->getRepository()->getRan();

            return count(array_diff(array_keys($files), $ran)) === 0;
        });

        // --- Core tables ---
        $check('Users table exists', fn () => Schema::hasTable('users'));
        $check('Super admin exists', fn () => User::where('role', 'super_admin')->exists());

        // --- Mail --- (config key is mail.default; mail.mailer does not exist)
        $check('Mail configured', fn () => config('mail.default') !== 'log');

        // --- Queue ---
        $check('Queue configured', fn () => config('queue.default') !== 'sync');

        // --- Storage ---
        $check('Storage writable', fn () => is_writable(storage_path('app')));
        $check('Storage symlink exists', fn () => file_exists(public_path('storage')));

        // --- Cache ---
        $check('Cache works', function (): bool {
            Cache::put('smoke_test', true, 10);
            $val = Cache::get('smoke_test');
            Cache::forget('smoke_test');

            return $val === true;
        });

        // --- Routes --- (Route::has is the real existence test; route()
        // returns a string and would always be truthy).
        $check('Dashboard route exists', fn () => Route::has('internal.dashboard'));
        $check('Portal route exists', fn () => Route::has('portal.dashboard'));

        // --- Stripe (only if configured) ---
        if (config('services.stripe.secret')) {
            $check('Stripe key format valid', fn () => str_starts_with(
                config('services.stripe.secret'),
                'sk_live_'
            ));
        }

        // --- Postmark (only if configured) ---
        if (config('services.postmark.token')) {
            $check('Postmark token set', fn () => strlen(config('services.postmark.token')) > 10);
        }

        $this->newLine();
        $this->info('Results: '.$pass.' passed, '.$fail.' failed');

        if ($fail > 0) {
            $this->error('❌ Smoke test FAILED — fix issues before going live');

            return self::FAILURE;
        }

        $this->info('✅ All checks passed — ready for go-live');

        return self::SUCCESS;
    }
}
