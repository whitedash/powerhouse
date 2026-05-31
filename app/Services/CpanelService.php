<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * cPanel UAPI client, scoped to a single website. Each site authenticates
 * with its OWN cPanel API token (stored encrypted on the website), so this
 * is constructed per-site rather than as a shared singleton.
 *
 * Usage: app(CpanelService::class, ['website' => $website])->syncAll()
 */
class CpanelService
{
    public function __construct(private Website $website)
    {
        if (empty($this->website->cpanel_username) || empty($this->website->cpanel_token)) {
            throw new \InvalidArgumentException('Website is missing cPanel credentials.');
        }
    }

    private function baseUrl(): string
    {
        return 'https://'.($this->website->cpanel_server ?: '040hosting.eu').':2083/execute/';
    }

    private function authHeader(): string
    {
        return 'cpanel '.$this->website->cpanel_username.':'.$this->website->cpanel_token;
    }

    /**
     * @return array{disk_used_mb: int, disk_quota_mb: int}
     */
    public function getDiskUsage(): array
    {
        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => $this->authHeader()])
            ->get($this->baseUrl().'DiskUsage/get_quota_info');

        $this->handleError($response);
        $data = $response->json('data') ?? [];

        return [
            'disk_used_mb' => (int) round((float) ($data['megabytes_used'] ?? 0)),
            'disk_quota_mb' => (int) round((float) ($data['megabyte_limit'] ?? 0)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getEmailAccounts(): array
    {
        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => $this->authHeader()])
            ->get($this->baseUrl().'Email/list_pops_with_disk');

        $this->handleError($response);
        $accounts = $response->json('data') ?? [];

        return [
            'email_accounts_count' => count($accounts),
            'email_accounts_list' => collect($accounts)->map(fn ($a): array => [
                'email' => $a['email'] ?? '',
                'disk_used_mb' => round(((float) ($a['_diskused'] ?? 0)) / 1024 / 1024, 2),
                'disk_quota_mb' => $a['_diskquota'] ?? 0,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{bandwidth_used_mb: int}
     */
    public function getBandwidth(): array
    {
        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => $this->authHeader()])
            ->get($this->baseUrl().'Bandwidth/query', [
                'year' => now()->year,
                'month' => now()->month,
                'interval' => 'month',
            ]);

        $this->handleError($response);
        $data = $response->json('data') ?? [];

        $bytesUsed = (float) collect($data)->sum('bytes');

        return [
            'bandwidth_used_mb' => (int) round($bytesUsed / 1024 / 1024),
        ];
    }

    /**
     * Pull disk, email count and bandwidth in one go, shaped for a direct
     * Website::update().
     *
     * @return array<string, mixed>
     */
    public function syncAll(): array
    {
        return array_merge(
            $this->getDiskUsage(),
            ['email_accounts_count' => $this->getEmailAccounts()['email_accounts_count']],
            $this->getBandwidth(),
            ['usage_checked_at' => now()],
        );
    }

    private function handleError(Response $response): void
    {
        if ($response->failed()) {
            throw new \RuntimeException(
                'cPanel API error: '.$response->status().' — '.Str::limit($response->body(), 200)
            );
        }
    }
}
