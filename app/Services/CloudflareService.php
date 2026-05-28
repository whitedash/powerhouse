<?php

namespace App\Services;

class CloudflareService
{
    public function syncZones(): void {}

    public function getZone(string $zoneId): ?array
    {
        return null;
    }

    public function createZone(string $domain): ?array
    {
        return null;
    }

    public function getSslStatus(string $zoneId): ?array
    {
        return null;
    }
}
