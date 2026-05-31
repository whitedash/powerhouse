<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WHM API client for the 040hosting.eu reseller account. Unlike
 * CpanelService (per-site token), WHM uses ONE global root token from
 * config to suspend / unsuspend / inspect any account on the server.
 */
class WhmService
{
    private function baseUrl(): string
    {
        return 'https://'.config('services.cpanel.whm_server', '040hosting.eu').':2087/json-api/';
    }

    private function authHeader(): string
    {
        return 'whm root:'.config('services.cpanel.whm_token');
    }

    public function suspendAccount(string $cpanelUsername, string $reason = 'Non-payment'): bool
    {
        $response = Http::timeout(15)
            ->withHeaders(['Authorization' => $this->authHeader()])
            ->post($this->baseUrl().'suspendacct', [
                'user' => $cpanelUsername,
                'reason' => $reason,
            ]);

        Log::info('WHM suspend', [
            'user' => $cpanelUsername,
            'status' => $response->status(),
            'response' => $response->json(),
        ]);

        return $response->successful();
    }

    public function unsuspendAccount(string $cpanelUsername): bool
    {
        $response = Http::timeout(15)
            ->withHeaders(['Authorization' => $this->authHeader()])
            ->post($this->baseUrl().'unsuspendacct', [
                'user' => $cpanelUsername,
            ]);

        Log::info('WHM unsuspend', [
            'user' => $cpanelUsername,
            'status' => $response->status(),
        ]);

        return $response->successful();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAccountSummary(string $cpanelUsername): ?array
    {
        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => $this->authHeader()])
            ->get($this->baseUrl().'accountsummary', [
                'user' => $cpanelUsername,
            ]);

        if ($response->failed()) {
            return null;
        }

        return $response->json('data.0');
    }

    public function testConnection(): bool
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['Authorization' => $this->authHeader()])
                ->get($this->baseUrl().'myprivs');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
