<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin wrapper over the Cloudflare v4 API.
 *
 * The token comes from config('services.cloudflare.token') and must
 * be a scoped API token (Zone:Read, DNS:Read, SSL and Certificates:
 * Read). Every outbound call carries a 6s timeout: this service is
 * exercised from synchronous controller paths and a daily cron,
 * neither of which wants to hang on a Cloudflare hiccup.
 */
class CloudflareService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    private const TIMEOUT = 6;

    public function __construct(private readonly ?string $token = null) {}

    /**
     * Verify the API token is present and active. Used by Settings →
     * Integrations "Test connection" so an operator can confirm the
     * Cloudflare integration is wired up.
     */
    public function testConnection(): bool
    {
        if (! $this->hasToken()) {
            return false;
        }

        try {
            $res = $this->client()->get(self::API_BASE.'/user/tokens/verify');
            if (! $res->successful()) {
                return false;
            }

            return ($res->json('result.status') ?? null) === 'active';
        } catch (Throwable $e) {
            Log::warning('Cloudflare token verify failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Pull the canonical zone state — name, status, plan, name
     * servers. Returns an empty array on any failure so callers can
     * use the `?? []` pattern without try/catch boilerplate.
     *
     * @return array<string, mixed>
     */
    public function getZoneDetails(string $zoneId): array
    {
        if (! $this->hasToken() || $zoneId === '') {
            return [];
        }

        try {
            $res = $this->client()->get(self::API_BASE.'/zones/'.$zoneId);
            if (! $res->successful()) {
                return [];
            }
            $r = $res->json('result') ?? [];

            return is_array($r) ? $r : [];
        } catch (Throwable $e) {
            Log::warning('Cloudflare zone details failed', ['zone' => $zoneId, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Live DNS records for a zone.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDnsRecords(string $zoneId): array
    {
        if (! $this->hasToken() || $zoneId === '') {
            return [];
        }

        try {
            $res = $this->client()
                ->get(self::API_BASE.'/zones/'.$zoneId.'/dns_records', ['per_page' => 100]);
            if (! $res->successful()) {
                return [];
            }
            $records = $res->json('result') ?? [];

            return is_array($records) ? $records : [];
        } catch (Throwable $e) {
            Log::warning('Cloudflare DNS list failed', ['zone' => $zoneId, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Fetch active SSL/TLS certificate packs and return the soonest
     * expiry — Cloudflare's Universal SSL exposes /certificates per
     * zone with expires_on timestamps.
     */
    public function getSslExpiry(string $zoneId): ?Carbon
    {
        if (! $this->hasToken() || $zoneId === '') {
            return null;
        }

        try {
            $res = $this->client()
                ->get(self::API_BASE.'/zones/'.$zoneId.'/ssl/certificate_packs');
            if (! $res->successful()) {
                return null;
            }

            $packs = $res->json('result') ?? [];
            if (! is_array($packs) || $packs === []) {
                return null;
            }

            $soonest = null;
            foreach ($packs as $pack) {
                $certs = $pack['certificates'] ?? [];
                if (! is_array($certs)) {
                    continue;
                }
                foreach ($certs as $cert) {
                    $exp = $cert['expires_on'] ?? null;
                    if (! is_string($exp)) {
                        continue;
                    }
                    $parsed = Carbon::parse($exp);
                    if ($soonest === null || $parsed->lt($soonest)) {
                        $soonest = $parsed;
                    }
                }
            }

            return $soonest;
        } catch (Throwable $e) {
            Log::warning('Cloudflare SSL expiry failed', ['zone' => $zoneId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Health snapshot for a domain. When a Cloudflare zone id is
     * attached the live data wins; otherwise we fall back to a
     * dns_get_record() lookup so dashboard tiles still show
     * something useful for non-Cloudflare domains.
     *
     * @return array{
     *     is_resolving: bool,
     *     ssl_valid: bool,
     *     ssl_expires_at: Carbon|null,
     *     nameservers: array<int, string>,
     *     cloudflare_proxied: bool,
     * }
     */
    public function checkDomainHealth(Domain $domain): array
    {
        $zoneId = (string) ($domain->cloudflare_zone_id ?? '');

        if ($zoneId !== '' && $this->hasToken()) {
            $zone = $this->getZoneDetails($zoneId);
            $sslExpiry = $this->getSslExpiry($zoneId);
            $records = $this->listDnsRecords($zoneId);

            $nameservers = $zone['name_servers'] ?? [];
            $isResolving = $records !== [];
            $cloudflareProxied = false;
            foreach ($records as $rec) {
                if (! empty($rec['proxied'])) {
                    $cloudflareProxied = true;

                    break;
                }
            }

            return [
                'is_resolving' => $isResolving,
                'ssl_valid' => $sslExpiry !== null && $sslExpiry->isFuture(),
                'ssl_expires_at' => $sslExpiry,
                'nameservers' => is_array($nameservers) ? array_values(array_map('strval', $nameservers)) : [],
                'cloudflare_proxied' => $cloudflareProxied,
            ];
        }

        return $this->plainDnsHealth($domain);
    }

    /**
     * Fallback when no Cloudflare zone is configured: ask the local
     * resolver for A / NS records. Cheap, doesn't require a token,
     * good enough for the dashboard signal.
     *
     * @return array{
     *     is_resolving: bool,
     *     ssl_valid: bool,
     *     ssl_expires_at: Carbon|null,
     *     nameservers: array<int, string>,
     *     cloudflare_proxied: bool,
     * }
     */
    private function plainDnsHealth(Domain $domain): array
    {
        $hostname = $domain->domain;
        $nameservers = [];
        $isResolving = false;

        try {
            $aRecords = @dns_get_record($hostname, DNS_A);
            $isResolving = is_array($aRecords) && $aRecords !== [];

            $nsRecords = @dns_get_record($hostname, DNS_NS);
            if (is_array($nsRecords)) {
                foreach ($nsRecords as $ns) {
                    if (! empty($ns['target'])) {
                        $nameservers[] = (string) $ns['target'];
                    }
                }
            }
        } catch (Throwable $e) {
            // Defaults stand — a failed lookup becomes "not resolving"
            // rather than crashing the sweep.
        }

        return [
            'is_resolving' => $isResolving,
            // No way to validate SSL without a TLS handshake; the
            // fallback path can't fill these in. The stored
            // ssl_expiry_date is preserved by the caller.
            'ssl_valid' => false,
            'ssl_expires_at' => null,
            'nameservers' => $nameservers,
            'cloudflare_proxied' => false,
        ];
    }

    private function hasToken(): bool
    {
        return $this->resolveToken() !== '';
    }

    private function resolveToken(): string
    {
        if ($this->token !== null && $this->token !== '') {
            return $this->token;
        }

        return (string) (config('services.cloudflare.token') ?? '');
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->resolveToken())
            ->acceptJson()
            ->timeout(self::TIMEOUT);
    }
}
