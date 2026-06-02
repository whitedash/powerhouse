<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\MainWPService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
                'php_version' => $w->php_version,
                'plugins_total' => $w->plugins_total,
                'plugins_outdated' => $w->plugins_outdated,
                'themes_outdated' => $w->themes_outdated,
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
        ]);
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

        try {
            $mainwp = app(MainWPService::class);
            $mainwp->updateSitePlugins($website->mainwp_site_id);

            // Re-sync so the stored counts reflect what actually updated.
            $fresh = $mainwp->getSite($website->mainwp_site_id);
            if ($fresh) {
                DB::transaction(fn () => $website->update($mainwp->mapSiteData($fresh)));
            }

            $updated = max(0, $before - $website->fresh()->plugins_outdated);
            $this->log($request, $website, $before, $website->plugins_outdated);

            return response()->json([
                'ok' => true,
                'message' => $updated > 0
                    ? $updated.' plugin'.($updated === 1 ? '' : 's').' updated'
                    : 'Update triggered',
                'plugins_outdated' => $website->plugins_outdated,
                'plugins_total' => $website->plugins_total,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    private function log(Request $request, Website $website, int $before, int $after): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'action' => 'website.plugins_updated',
            'entity_type' => 'website',
            'entity_id' => $website->id,
            'before' => ['plugins_outdated' => $before],
            'after' => ['plugins_outdated' => $after],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
