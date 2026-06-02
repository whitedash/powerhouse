<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * MainWP dashboard REST client. MainWP exposes a WooCommerce-style API
 * under /wp-json/mainwp/v2 secured by a consumer key + secret passed as
 * query params. We use it read-mostly: list child sites, fetch one site,
 * trigger a sync, and map the response onto the Website telemetry columns
 * (WP/PHP version, plugin/theme update counts, last backup).
 *
 * Every method assumes config('services.mainwp.enabled') has already been
 * checked by the caller — the command + controller both gate on it so an
 * unconfigured install is a graceful no-op rather than a stream of errors.
 */
class MainWPService
{
    private function baseUrl(): string
    {
        return rtrim((string) config('services.mainwp.url'), '/').'/wp-json/mainwp/v2';
    }

    /**
     * MainWP authenticates via a Bearer token that is the consumer key and
     * secret joined with `==` (key==secret) — not WooCommerce-style query
     * params. The two halves are stored separately in .env and combined
     * here.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.config('services.mainwp.consumer_key').'=='.config('services.mainwp.consumer_secret'),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Lightweight credential check — hit /sites and report whether the
     * dashboard accepted the key. Never throws; a bad URL or timeout
     * resolves to false so the Settings "Test connection" button can
     * report cleanly.
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders($this->headers())
                ->get($this->baseUrl().'/sites');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * List every child site registered on the dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSites(): array
    {
        $response = Http::timeout(30)
            ->withHeaders($this->headers())
            ->get($this->baseUrl().'/sites');

        if ($response->failed()) {
            throw new \RuntimeException('MainWP API error: '.$response->status());
        }

        // MainWP wraps the list under "data" ({success, total, data}).
        return $response->json('data') ?? [];
    }

    /**
     * Fetch a single child site by its MainWP site ID. Returns null on a
     * non-2xx so callers can surface a "not found" message.
     *
     * @return array<string, mixed>|null
     */
    public function getSite(int $siteId): ?array
    {
        // Single-site route is /sites/{id} (plural) and wraps the site
        // object under "data" — same envelope as the list endpoint.
        $response = Http::timeout(15)
            ->withHeaders($this->headers())
            ->get($this->baseUrl().'/sites/'.$siteId);

        if ($response->failed()) {
            return null;
        }

        return $response->json('data');
    }

    /**
     * Trigger a sync on the child site and return its refreshed payload.
     *
     * @return array<string, mixed>
     */
    public function syncSite(int $siteId): array
    {
        $response = Http::timeout(60)
            ->withHeaders($this->headers())
            ->post($this->baseUrl().'/sites/'.$siteId.'/sync');

        if ($response->failed()) {
            throw new \RuntimeException('MainWP sync failed: '.$response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * Pending updates for a single child site, keyed by type. MainWP returns
     * {success, data:{wp, plugins, themes, translations}} where each list is
     * a slug-keyed map of items carrying Name / Version. Used to preview what
     * a plugin update would touch before triggering it.
     *
     * @return array{wp: array<string,mixed>, plugins: array<string,mixed>, themes: array<string,mixed>, translations: array<string,mixed>}
     */
    public function getSitePendingUpdates(int|string $site): array
    {
        $response = Http::timeout(30)
            ->withHeaders($this->headers())
            ->get($this->baseUrl().'/updates/'.$site);

        if ($response->failed()) {
            throw new \RuntimeException('MainWP updates lookup failed: '.$response->status());
        }

        $data = $response->json('data') ?? [];

        return [
            'wp' => $data['wp'] ?? [],
            'plugins' => $data['plugins'] ?? [],
            'themes' => $data['themes'] ?? [],
            'translations' => $data['translations'] ?? [],
        ];
    }

    /**
     * Update a single plugin on a child site, identified by its slug
     * (folder/file.php). The v2 endpoint targets one plugin via a singular
     * `slug` parameter. Throws on a non-2xx or when MainWP reports success:0.
     *
     * @return array<string, mixed>
     */
    public function updateSitePlugin(int|string $site, string $slug): array
    {
        // MainWP expects the plugin slug (dir/file.php) as a SINGULAR `slug`
        // parameter — a plugins[] array is ignored and the endpoint returns
        // 200 {"success":0,"message":"No Plugins to update."}. Per the v2
        // schema slug is a query param; we also send it in the body so the
        // request works whichever way the build reads params.
        $url = $this->baseUrl().'/updates/'.$site.'/update/plugins?slug='.rawurlencode($slug);
        $response = Http::timeout(120)->withHeaders($this->headers())->post($url, ['slug' => $slug]);

        return $this->assertUpdateApplied($response, 'plugin');
    }

    /**
     * MainWP returns HTTP 200 with success:0 (e.g. "No Plugins to update.")
     * when it did not apply anything — that must be treated as a failure, not
     * blindly as success. Throws with MainWP's own message on a non-success.
     *
     * @return array<string, mixed>
     */
    private function assertUpdateApplied(Response $response, string $what): array
    {
        if ($response->failed()) {
            throw new \RuntimeException('MainWP '.$what.' update failed: '.$response->status().' '.$response->body());
        }

        $json = $response->json() ?? [];
        if (empty($json['success'])) {
            throw new \RuntimeException('MainWP did not apply the '.$what.' update: '.($json['message'] ?? 'unknown response'));
        }

        return $json;
    }

    /**
     * Update a single theme on a child site, by slug — same singular `slug`
     * contract as plugins. Throws on a non-2xx or a success:0 response.
     *
     * @return array<string, mixed>
     */
    public function updateSiteTheme(int|string $site, string $slug): array
    {
        // Same contract as plugins — singular `slug` query/body param.
        $url = $this->baseUrl().'/updates/'.$site.'/update/themes?slug='.rawurlencode($slug);
        $response = Http::timeout(120)->withHeaders($this->headers())->post($url, ['slug' => $slug]);

        return $this->assertUpdateApplied($response, 'theme');
    }

    /**
     * Activate or deactivate a single plugin on a child site, by slug. Hits
     * the dedicated /plugins/activate or /plugins/deactivate route with the
     * slug under `plugins`. Throws on a non-2xx.
     *
     * @return array<string, mixed>
     */
    public function setSitePluginState(int|string $site, string $slug, bool $activate): array
    {
        $action = $activate ? 'activate' : 'deactivate';

        // Same singular `slug` contract as the update endpoints — a plugins[]
        // value is rejected (HTTP 500). Send slug as a query param + body.
        $url = $this->baseUrl().'/sites/'.$site.'/plugins/'.$action.'?slug='.rawurlencode($slug);
        $response = Http::timeout(60)->withHeaders($this->headers())->post($url, ['slug' => $slug]);

        return $this->assertUpdateApplied($response, 'plugin '.$action);
    }

    /**
     * Update WordPress core on a child site to the latest available version.
     * The endpoint is idempotent — a no-op when core is already current.
     * Throws on a non-2xx.
     *
     * @return array<string, mixed>
     */
    public function updateSiteCore(int|string $site): array
    {
        $response = Http::timeout(180)
            ->withHeaders($this->headers())
            ->post($this->baseUrl().'/updates/'.$site.'/update/wp');

        return $this->assertUpdateApplied($response, 'core');
    }

    /**
     * Map a MainWP site response onto the Website telemetry columns. Shape
     * is defensive: missing keys fall back to null / 0 so a partial
     * response never blows up an ->update().
     *
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    public function mapSiteData(array $site): array
    {
        // MainWP delivers plugins / themes and their *_upgrades lists as
        // JSON-encoded strings, not arrays. Decode leniently — some builds
        // may already hand back an array, and a malformed string becomes [].
        $decode = function ($val): array {
            if (is_array($val)) {
                return $val;
            }
            if (is_string($val)) {
                $decoded = json_decode($val, true);

                return is_array($decoded) ? $decoded : [];
            }

            return [];
        };

        $plugins = $decode($site['plugins'] ?? []);
        $themes = $decode($site['themes'] ?? []);

        // plugin_upgrades / theme_upgrades already contain only the items
        // with a pending update (keyed by slug), so their size is the
        // outdated count — more reliable than scanning an `update` flag.
        $pluginUpgrades = $decode($site['plugin_upgrades'] ?? []);
        $themeUpgrades = $decode($site['theme_upgrades'] ?? []);

        // Core upgrade is reported under wp_upgrades as {current, new} when
        // pending, empty otherwise. We keep the boolean + target version.
        $wpUpgrades = $decode($site['wp_upgrades'] ?? []);
        $wpLatest = isset($wpUpgrades['new']) ? (string) $wpUpgrades['new'] : null;

        return [
            'wp_version' => $site['wp_version'] ?? null,
            'wp_core_update_available' => $wpLatest !== null,
            'wp_latest_version' => $wpLatest,
            'php_version' => $site['php_version'] ?? null,
            'plugins_total' => count($plugins),
            'plugins_outdated' => count($pluginUpgrades),
            'themes_outdated' => count($themeUpgrades),
            'plugin_updates_detail' => $this->extractUpdateDetail($pluginUpgrades),
            'theme_updates_detail' => $this->extractUpdateDetail($themeUpgrades),
            'last_backup_at' => $this->parseBackupDate($site['last_backup'] ?? null),
            'mainwp_site_id' => $site['id'] ?? null,
        ];
    }

    /**
     * Reduce a slug-keyed *_upgrades map to the slim per-item breakdown the
     * WP Updates page renders. MainWP items use capitalised WP header keys
     * (Name, Version) and carry the target version under update.new_version
     * alongside a large bundled changelog we deliberately drop. The map key
     * is the plugin/theme slug. Lower-case fallbacks cover other builds.
     *
     * @param  array<array-key, mixed>  $upgrades
     * @return array<int, array{name: string, slug: string, current_version: string, new_version: string, active: bool}>
     */
    private function extractUpdateDetail(array $upgrades): array
    {
        $out = [];

        foreach ($upgrades as $slug => $item) {
            if (! is_array($item)) {
                continue;
            }

            $update = $item['update'] ?? [];
            $newVersion = is_array($update)
                ? ($update['new_version'] ?? '—')
                : (is_string($update) ? $update : '—');

            $out[] = [
                'name' => (string) ($item['Name'] ?? $item['name'] ?? (is_string($slug) ? $slug : 'Unknown')),
                'slug' => (string) (is_string($slug) && $slug !== '' ? $slug : ($item['slug'] ?? '')),
                'current_version' => (string) ($item['Version'] ?? $item['version'] ?? '—'),
                'new_version' => (string) $newVersion,
                'active' => (bool) ($item['active'] ?? true),
            ];
        }

        return $out;
    }

    /**
     * MainWP reports last-backup as a date string or a unix timestamp
     * depending on the backup extension. Parse leniently; an unparseable
     * or empty value becomes null rather than throwing mid-sweep.
     */
    private function parseBackupDate(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value)
                : Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
