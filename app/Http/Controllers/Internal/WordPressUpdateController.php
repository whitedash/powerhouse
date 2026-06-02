<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\MainWPService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bulk WordPress plugin updates across MainWP-linked sites. Lists every
 * managed site that has outstanding plugin updates (from the data the
 * websites:sync-wordpress sweep already stores) and lets a super_admin
 * push the updates per site via MainWP — no per-site application
 * passwords needed, the dashboard's own connection does the work.
 *
 * Updates run one site per request (the frontend loops the selection) so
 * each call stays inside the HTTP timeout and the UI shows live per-site
 * progress. super_admin only — gated at the route — because this mutates
 * live customer sites.
 */
class WordPressUpdateController extends Controller
{
    public function index(): Response
    {
        $websites = Website::query()
            ->whereNotNull('mainwp_site_id')
            ->where('status', 'active')
            ->with('customer:id,name')
            ->orderByDesc('plugins_outdated')
            ->get();

        return Inertia::render('Internal/WordPress/Updates', [
            'configured' => (bool) config('services.mainwp.enabled'),
            'sites' => $websites->map(fn (Website $w): array => [
                'id' => $w->id,
                'name' => $w->name,
                'url' => $w->url,
                'customer_id' => $w->customer_id,
                'customer_name' => $w->customer?->name,
                'wp_version' => $w->wp_version,
                'wp_core_update_available' => $w->wp_core_update_available,
                'wp_latest_version' => $w->wp_latest_version,
                'php_version' => $w->php_version,
                'plugins_total' => $w->plugins_total,
                'plugins_outdated' => $w->plugins_outdated,
                'themes_outdated' => $w->themes_outdated,
                'plugin_updates' => $w->plugin_updates_detail ?? [],
                'theme_updates' => $w->theme_updates_detail ?? [],
                'last_synced' => $w->updated_at?->diffForHumans(),
            ])->values(),
            // Distinct customers among the linked sites — powers the
            // "select all of X's sites" shortcut in the UI.
            'customers' => $websites
                ->filter(fn (Website $w): bool => $w->customer !== null)
                ->map(fn (Website $w): array => ['id' => $w->customer_id, 'name' => $w->customer?->name])
                ->unique('id')
                ->sortBy('name')
                ->values(),
            // The same outstanding updates inverted to a per-plugin view —
            // "which plugins are out of date, and on how many sites".
            'plugins_with_updates' => $this->aggregateByPlugin($websites),
            // MainWP exposes plugin activate/deactivate routes, so the per-row
            // toggle is available whenever the integration is configured.
            'toggle_supported' => (bool) config('services.mainwp.enabled'),
        ]);
    }

    /**
     * Invert the per-site update detail into a per-plugin overview: one row
     * per distinct plugin slug, carrying the affected sites and their
     * current versions, sorted by reach (most widespread first). Read-only —
     * MainWP REST has no per-plugin update endpoint (the /update/plugins call
     * updates every outstanding plugin on a site), so this is presentation
     * only; updates are driven per-site from the "By Site" tab.
     *
     * @param  Collection<int, Website>  $websites
     * @return array<int, array<string, mixed>>
     */
    private function aggregateByPlugin($websites): array
    {
        $byPlugin = [];

        foreach ($websites as $website) {
            foreach ($website->plugin_updates_detail ?? [] as $plugin) {
                $slug = $plugin['slug'] ?? '';
                if ($slug === '') {
                    continue;
                }

                if (! isset($byPlugin[$slug])) {
                    $byPlugin[$slug] = [
                        'slug' => $slug,
                        'name' => $plugin['name'] ?? $slug,
                        'new_version' => $plugin['new_version'] ?? '—',
                        'sites' => [],
                        'site_count' => 0,
                    ];
                }

                $byPlugin[$slug]['sites'][] = [
                    'website_id' => $website->id,
                    'mainwp_site_id' => $website->mainwp_site_id,
                    'url' => $website->url,
                    'customer_name' => $website->customer?->name,
                    'current_version' => $plugin['current_version'] ?? '—',
                ];
                $byPlugin[$slug]['site_count']++;
            }
        }

        $rows = array_values($byPlugin);
        usort($rows, fn (array $a, array $b): int => $b['site_count'] <=> $a['site_count']);

        return $rows;
    }

    /**
     * Update all outstanding plugins on a single linked site, then re-sync
     * its telemetry so the counts reflect the result. Returns JSON for the
     * frontend's per-site progress loop.
     */
    public function updateSite(int $id, Request $request): JsonResponse
    {
        $website = Website::findOrFail($id);
        Gate::authorize('update', $website->customer);

        if (! config('services.mainwp.enabled')) {
            return response()->json(['ok' => false, 'message' => 'MainWP is not configured.'], 422);
        }
        if (! $website->mainwp_site_id) {
            return response()->json(['ok' => false, 'message' => 'Site is not linked to MainWP.'], 422);
        }

        $before = $website->plugins_outdated;

        // MainWP has no bulk "update all" — each plugin is updated by slug, so
        // loop the outstanding list. One failure doesn't abort the rest.
        $slugs = collect($website->plugin_updates_detail ?? [])->pluck('slug')->filter()->values();
        $mainwp = app(MainWPService::class);
        $failed = 0;

        foreach ($slugs as $slug) {
            try {
                $mainwp->updateSitePlugin($website->mainwp_site_id, $slug);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('mainwp.plugin_update_failed', ['site' => $website->url, 'slug' => $slug, 'error' => $e->getMessage()]);
            }
        }

        $this->resync($mainwp, $website);
        $updated = max(0, $before - $website->plugins_outdated);
        $this->record($request, $website, 'website.plugins_updated', ['plugins_outdated' => $before], ['plugins_outdated' => $website->plugins_outdated, 'failed' => $failed]);

        return response()->json([
            'ok' => $failed === 0,
            'message' => $failed > 0
                ? $updated.' updated, '.$failed.' failed'
                : $updated.' plugin'.($updated === 1 ? '' : 's').' updated',
            'plugins_outdated' => $website->plugins_outdated,
            'plugins_total' => $website->plugins_total,
            'themes_outdated' => $website->themes_outdated,
            'plugin_updates' => $website->plugin_updates_detail ?? [],
            'theme_updates' => $website->theme_updates_detail ?? [],
        ]);
    }

    /**
     * Update WordPress core on a single linked site, then re-sync telemetry.
     */
    public function updateCore(Request $request): JsonResponse
    {
        $data = $request->validate(['website_id' => ['required', 'integer', 'exists:websites,id']]);
        $website = Website::findOrFail($data['website_id']);
        Gate::authorize('update', $website->customer);

        if ($error = $this->guard($website)) {
            return $error;
        }

        try {
            $mainwp = app(MainWPService::class);
            $result = $mainwp->updateSiteCore($website->mainwp_site_id);
            Log::info('mainwp.core_update', ['site' => $website->url, 'response' => $result]);

            $this->resync($mainwp, $website);

            // Core still flagged after a fresh sync = update hasn't applied.
            $pending = (bool) $website->wp_core_update_available;
            $this->record($request, $website, 'website.core_updated', null, ['wp_version' => $website->wp_version, 'applied' => ! $pending]);

            return response()->json([
                'ok' => ! $pending,
                'pending' => $pending,
                'message' => $pending
                    ? 'MainWP accepted the request but core still shows an update — it may be processing. Re-sync the site in a minute to confirm.'
                    : 'WordPress core updated',
                'wp_version' => $website->wp_version,
                'wp_core_update_available' => $website->wp_core_update_available,
                'wp_latest_version' => $website->wp_latest_version,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * Update a single plugin on a linked site, then re-sync telemetry.
     */
    public function updatePlugin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'website_id' => ['required', 'integer', 'exists:websites,id'],
            'plugin_slug' => ['required', 'string', 'max:255'],
        ]);
        $website = Website::findOrFail($data['website_id']);
        Gate::authorize('update', $website->customer);

        if ($error = $this->guard($website)) {
            return $error;
        }

        try {
            $mainwp = app(MainWPService::class);
            $result = $mainwp->updateSitePlugin($website->mainwp_site_id, $data['plugin_slug']);
            // Record what MainWP actually returned — previously we were blind
            // to this, which is why a no-op looked like success.
            Log::info('mainwp.plugin_update', ['site' => $website->url, 'slug' => $data['plugin_slug'], 'response' => $result]);

            $this->resync($mainwp, $website);

            // Verify rather than assume: if the plugin is still in the outdated
            // list after a fresh sync, the update hasn't applied (queued, failed,
            // or the per-plugin target was ignored).
            $pending = $this->stillListed($website->plugin_updates_detail, $data['plugin_slug']);
            $this->record($request, $website, 'website.plugin_updated', ['plugin' => $data['plugin_slug']], ['plugins_outdated' => $website->plugins_outdated, 'applied' => ! $pending]);

            return response()->json([
                'ok' => ! $pending,
                'pending' => $pending,
                'message' => $pending
                    ? 'MainWP accepted the request but the plugin still shows an update — it may be processing. Re-sync the site in a minute to confirm.'
                    : 'Plugin updated',
                'plugins_outdated' => $website->plugins_outdated,
                'plugins_total' => $website->plugins_total,
                'themes_outdated' => $website->themes_outdated,
                'plugin_updates' => $website->plugin_updates_detail ?? [],
                'theme_updates' => $website->theme_updates_detail ?? [],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * Update a single theme on a linked site, then re-sync telemetry.
     */
    public function updateTheme(Request $request): JsonResponse
    {
        $data = $request->validate([
            'website_id' => ['required', 'integer', 'exists:websites,id'],
            'theme_slug' => ['required', 'string', 'max:255'],
        ]);
        $website = Website::findOrFail($data['website_id']);
        Gate::authorize('update', $website->customer);

        if ($error = $this->guard($website)) {
            return $error;
        }

        try {
            $mainwp = app(MainWPService::class);
            $result = $mainwp->updateSiteTheme($website->mainwp_site_id, $data['theme_slug']);
            Log::info('mainwp.theme_update', ['site' => $website->url, 'slug' => $data['theme_slug'], 'response' => $result]);

            $this->resync($mainwp, $website);

            $pending = $this->stillListed($website->theme_updates_detail, $data['theme_slug']);
            $this->record($request, $website, 'website.theme_updated', ['theme' => $data['theme_slug']], ['themes_outdated' => $website->themes_outdated, 'applied' => ! $pending]);

            return response()->json([
                'ok' => ! $pending,
                'pending' => $pending,
                'message' => $pending
                    ? 'MainWP accepted the request but the theme still shows an update — it may be processing. Re-sync the site in a minute to confirm.'
                    : 'Theme updated',
                'plugins_outdated' => $website->plugins_outdated,
                'themes_outdated' => $website->themes_outdated,
                'plugin_updates' => $website->plugin_updates_detail ?? [],
                'theme_updates' => $website->theme_updates_detail ?? [],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * Activate or deactivate a single plugin on a linked site. MainWP exposes
     * /plugins/activate and /plugins/deactivate routes for this.
     */
    public function togglePlugin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'website_id' => ['required', 'integer', 'exists:websites,id'],
            'plugin_slug' => ['required', 'string', 'max:255'],
            'action' => ['required', 'in:activate,deactivate'],
        ]);
        $website = Website::findOrFail($data['website_id']);
        Gate::authorize('update', $website->customer);

        if ($error = $this->guard($website)) {
            return $error;
        }

        try {
            $mainwp = app(MainWPService::class);
            $mainwp->setSitePluginState($website->mainwp_site_id, $data['plugin_slug'], $data['action'] === 'activate');
            $this->resync($mainwp, $website);
            $this->record($request, $website, 'website.plugin_'.$data['action'].'d', ['plugin' => $data['plugin_slug']], null);

            return response()->json([
                'ok' => true,
                'message' => 'Plugin '.($data['action'] === 'activate' ? 'activated' : 'deactivated'),
                'plugin_updates' => $website->plugin_updates_detail ?? [],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * Shared guard for the action endpoints: MainWP configured + site linked.
     */
    private function guard(Website $website): ?JsonResponse
    {
        if (! config('services.mainwp.enabled')) {
            return response()->json(['ok' => false, 'message' => 'MainWP is not configured.'], 422);
        }
        if (! $website->mainwp_site_id) {
            return response()->json(['ok' => false, 'message' => 'Site is not linked to MainWP.'], 422);
        }

        return null;
    }

    /**
     * Pull fresh telemetry for a site and persist the mapped fields.
     */
    private function resync(MainWPService $mainwp, Website $website): void
    {
        // Trigger a child sync first so MainWP re-reads the site's current
        // versions — getSite() otherwise returns MainWP's last-cached view,
        // which still shows the pre-update version right after an update.
        // Best-effort: a sync failure must not wipe the data we already have.
        try {
            $mainwp->syncSite($website->mainwp_site_id);
        } catch (\Throwable) {
            // fall through to the last-known state
        }

        $fresh = $mainwp->getSite($website->mainwp_site_id);
        if ($fresh) {
            DB::transaction(fn () => $website->update($mainwp->mapSiteData($fresh)));
        }
    }

    /**
     * True when a slug is still present in a *_updates_detail list — i.e. the
     * update has not (yet) applied.
     *
     * @param  array<int, array<string, mixed>>|null  $detail
     */
    private function stillListed(?array $detail, string $slug): bool
    {
        return collect($detail ?? [])->contains(fn ($item): bool => ($item['slug'] ?? '') === $slug);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Request $request, Website $website, string $action, ?array $before, ?array $after): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => $action,
            'entity_type' => 'website',
            'entity_id' => $website->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
