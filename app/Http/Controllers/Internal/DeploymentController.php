<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SchemaMigration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Super-admin deployment maintenance: preview + run pending migrations and
 * clear caches from the UI — replacing the public migrate.php / clear-cache.php
 * helper scripts.
 *
 * Deliberately SELF-CONTAINED: it touches only Artisan + ActivityLog (a
 * long-stable core table), and never loads application models/relations that
 * might reference a column added by a not-yet-run migration. That keeps this
 * page reachable immediately after a deploy, before `migrate` has run — which
 * is exactly when you need it.
 */
class DeploymentController extends Controller
{
    /**
     * Run-history page size. DELIBERATE exception to the app-wide 20/page
     * standard — 10 here, per request, since each row is a single migration
     * name and the history is browsed, not scanned.
     */
    private const HISTORY_PER_PAGE = 10;

    public function index(Request $request): Response
    {
        Gate::authorize('manage-deployment');

        return Inertia::render('Internal/Settings/Deployment', array_merge(
            $this->migrationState(),
            // Output of the most recent action, flashed by the POST handlers.
            ['last_run' => $request->session()->get('deployment_last_run')],
        ));
    }

    public function migrate(Request $request): RedirectResponse
    {
        Gate::authorize('manage-deployment');

        return $this->run($request, 'migrate', function (): string {
            Artisan::call('migrate', ['--force' => true]);
            $out = trim(Artisan::output());

            // Best-effort: clear the compiled-class opcache so the new code +
            // schema are picked up by all PHP workers. Guarded — absent on CLI
            // / when disabled.
            if (function_exists('opcache_reset')) {
                @opcache_reset();
                $out .= "\n[opcache reset]";
            }

            return $out;
        });
    }

    public function clearCache(Request $request): RedirectResponse
    {
        Gate::authorize('manage-deployment');

        return $this->run($request, 'clear-cache', function (): string {
            Artisan::call('optimize:clear');

            return trim(Artisan::output());
        });
    }

    public function runBoth(Request $request): RedirectResponse
    {
        Gate::authorize('manage-deployment');

        return $this->run($request, 'run-both', function (): string {
            Artisan::call('migrate', ['--force' => true]);
            $migrate = trim(Artisan::output());
            Artisan::call('optimize:clear');
            $clear = trim(Artisan::output());

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return "$ migrate --force\n".$migrate."\n\n$ optimize:clear\n".$clear;
        });
    }

    /**
     * Run one maintenance action: capture its output, log it, flash the
     * result back to the Deployment page.
     */
    private function run(Request $request, string $action, callable $callback): RedirectResponse
    {
        $startedAt = now();
        $ok = true;

        try {
            $output = $callback();
        } catch (Throwable $e) {
            $ok = false;
            $output = $e->getMessage();
        }

        $this->log($request, $action, $ok, $output);

        $labels = ['migrate' => 'Migrations', 'clear-cache' => 'Caches', 'run-both' => 'Migrations + caches'];
        $label = $labels[$action] ?? $action;

        return back()
            ->with($ok ? 'success' : 'error', $ok ? "{$label} run." : "{$label} failed — see output.")
            ->with('deployment_last_run', [
                'action' => $action,
                'ok' => $ok,
                'output' => $output !== '' ? $output : '(no output)',
                'ran_at' => $startedAt->toIso8601String(),
                'ran_by' => $request->user()?->name,
            ]);
    }

    /**
     * The pending list + count (via the migrator, the same data
     * `migrate:status` shows), the raw `migrate:status` text for a familiar
     * preview, and the paginated run history. Guards the no-migrations-table
     * case (fresh DB).
     *
     * @return array<string, mixed>
     */
    private function migrationState(): array
    {
        // Raw preview, like the old migrate.php script.
        Artisan::call('migrate:status');
        $statusOutput = trim(Artisan::output());

        $migrator = app('migrator');
        $names = array_keys($migrator->getMigrationFiles(database_path('migrations')));

        try {
            $ran = $migrator->getRepository()->getRan();
        } catch (Throwable $e) {
            // migrations table doesn't exist yet → everything is pending.
            $ran = [];
        }

        $pending = collect($names)
            ->reject(fn (string $name): bool => in_array($name, $ran, true))
            ->map(fn (string $name): array => ['name' => $name])
            ->values();

        return [
            'status_output' => $statusOutput,
            'ran_migrations' => $this->ranHistory(),
            'pending' => $pending->all(),
            'pending_count' => $pending->count(),
        ];
    }

    /**
     * The run history, NEWEST-FIRST by true run order (migrations.id DESC),
     * paginated at 10/page with the ?page query string preserved. Guards the
     * no-migrations-table case (fresh DB, pre-migrate) with an empty paginator
     * so the page stays reachable immediately after a deploy.
     *
     * @return LengthAwarePaginator<int, array{id: int, name: string, batch: int}>
     */
    private function ranHistory(): LengthAwarePaginator
    {
        try {
            return SchemaMigration::query()
                ->orderByDesc('id')
                ->paginate(self::HISTORY_PER_PAGE)
                ->withQueryString()
                ->through(fn (SchemaMigration $m): array => [
                    'id' => (int) $m->id,
                    'name' => (string) $m->migration,
                    'batch' => (int) $m->batch,
                ]);
        } catch (Throwable $e) {
            // migrations table doesn't exist yet → empty history.
            /** @var LengthAwarePaginator<int, array{id: int, name: string, batch: int}> $empty */
            $empty = new LengthAwarePaginator([], 0, self::HISTORY_PER_PAGE, 1, [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]);

            return $empty;
        }
    }

    private function log(Request $request, string $action, bool $ok, string $output): void
    {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => 'deployment.'.$action,
            'entity_type' => 'deployment',
            'entity_id' => 0,
            'before' => null,
            'after' => [
                'ok' => $ok,
                // Keep the audit row bounded — the full output is transient.
                'output' => mb_substr($output, 0, 2000),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
