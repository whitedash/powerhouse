<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Produce a clean production-ready mysqldump, excluding volatile/runtime
 * tables (sessions, queue, OAuth tokens, telescope) so the dump carries
 * only durable business data.
 *
 * The DB password is passed via a temporary --defaults-extra-file rather
 * than on the command line — a bare -p<password> would leak the secret
 * into the host process list (`ps aux`).
 */
class ExportForProduction extends Command
{
    protected $signature = 'export:production
        {--output= : Output file path}';

    protected $description = 'Export a clean production-ready SQL dump (excludes sessions, queue, token + telescope tables).';

    public function handle(): int
    {
        $output = $this->option('output')
            ?? storage_path('app/production-'.now()->format('Y-m-d').'.sql');

        $db = config('database.connections.mysql');

        $ignoreTables = [
            'sessions',
            'jobs',
            'job_batches',
            'failed_jobs',
            'cache',
            'cache_locks',
            'oauth_access_tokens',
            'oauth_refresh_tokens',
            'oauth_auth_codes',
            'telescope_entries',
            'telescope_entries_tags',
            'telescope_monitoring',
        ];

        $ignoreFlags = implode(' ', array_map(
            fn ($t) => '--ignore-table='.escapeshellarg("{$db['database']}.{$t}"),
            $ignoreTables
        ));

        // Credentials go in a 0600 temp file so they never hit the
        // process list. Cleaned up in finally, even on failure.
        $defaultsFile = tempnam(sys_get_temp_dir(), 'phdump');
        file_put_contents($defaultsFile, sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=\"%s\"\n",
            $db['host'],
            $db['port'] ?? 3306,
            $db['username'],
            addslashes((string) $db['password']),
        ));
        chmod($defaultsFile, 0600);

        try {
            $cmd = sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --no-tablespaces %s %s > %s',
                escapeshellarg($defaultsFile),
                $ignoreFlags,
                escapeshellarg($db['database']),
                escapeshellarg($output),
            );

            exec($cmd, $out, $code);
        } finally {
            @unlink($defaultsFile);
        }

        if ($code !== 0 || ! file_exists($output) || filesize($output) === 0) {
            $this->error('Export failed.');

            return self::FAILURE;
        }

        $size = round(filesize($output) / 1024 / 1024, 1);
        $this->info('✅ Exported to: '.$output);
        $this->info('   Size: '.$size.' MB');
        $this->newLine();
        $this->info('Transfer to production:');
        $this->line('scp '.$output.' user@040hosting.eu:~/');
        $this->line('mysql -u prod_user -p powerhouse < '.basename($output));

        return self::SUCCESS;
    }
}
