<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Reject URLs that resolve to private / loopback / link-local addresses.
 * Required on any field that accepts a URL the server later fetches,
 * preventing SSRF against internal services (DBs, AWS metadata, k8s API).
 */
class NotInternalUrl implements ValidationRule
{
    private const PRIVATE_RANGES_V4 = [
        '127.0.0.0/8',     // Loopback
        '10.0.0.0/8',      // RFC 1918 private
        '172.16.0.0/12',   // RFC 1918 private
        '192.168.0.0/16',  // RFC 1918 private
        '169.254.0.0/16',  // Link-local (AWS metadata 169.254.169.254)
        '0.0.0.0/8',       // "This network"
        '100.64.0.0/10',   // Carrier-grade NAT
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Invalid URL format.');

            return;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);

        if (! $host || ! in_array($scheme, ['http', 'https'], true)) {
            $fail('URL must be an absolute http(s) URL.');

            return;
        }

        $ip = gethostbyname($host);

        // gethostbyname returns the host string unchanged on failure.
        if ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP)) {
            $fail('Unable to resolve URL host.');

            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($ip === '::1' || str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd') || str_starts_with($ip, 'fe80')) {
                $fail('URL resolves to an internal address.');
            }

            return;
        }

        foreach (self::PRIVATE_RANGES_V4 as $range) {
            if ($this->ipInRange($ip, $range)) {
                $fail('URL resolves to an internal address.');

                return;
            }
        }
    }

    private function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);
        $subnetLong &= $mask;

        return ($ipLong & $mask) === $subnetLong;
    }
}
