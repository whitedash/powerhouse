<?php

namespace App\Services;

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

        return [
            'wp_version' => $site['wp_version'] ?? null,
            'php_version' => $site['php_version'] ?? null,
            'plugins_total' => count($plugins),
            'plugins_outdated' => count($pluginUpgrades),
            'themes_outdated' => count($themeUpgrades),
            'last_backup_at' => $this->parseBackupDate($site['last_backup'] ?? null),
            'mainwp_site_id' => $site['id'] ?? null,
        ];
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
