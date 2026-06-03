<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Mints and verifies short-lived cross-app SSO tokens.
 *
 * Token format:  base64url(json(payload)) . hex(hmac_sha256(b64, secret))
 *
 * The consumer app (MyOrderPad) holds the same POWERHOUSE_SSO_SECRET and
 * re-derives the HMAC to authenticate the token, then auto-logs-in the
 * referenced account. The token is intentionally tiny-lived (60s) because
 * it travels in the launch URL; the consumer additionally enforces
 * single-use via the `jti`. We sign — never encrypt — the payload: it
 * carries no secret, only identifiers the consumer already knows.
 */
class SsoService
{
    /** Token lifetime in seconds. Long enough to survive a redirect, short enough that a leaked URL is near-useless. */
    public const TOKEN_TTL = 60;

    public function mintToken(int $customerId, string $externalUserId, string $product): string
    {
        $payload = [
            'powerhouse_customer_id' => $customerId,
            'external_user_id' => $externalUserId,
            'product' => $product,
            'iat' => time(),
            'exp' => time() + self::TOKEN_TTL,
            'jti' => Str::uuid()->toString(),
        ];

        $b64 = $this->base64UrlEncode((string) json_encode($payload));
        $sig = hash_hmac('sha256', $b64, $this->secret());

        return $b64.'.'.$sig;
    }

    /**
     * Verify + decode a token. Returns the payload array, or null on any
     * failure (bad shape, bad signature, expired). Internal/debug use —
     * the authoritative verifier is the consumer app's own copy.
     *
     * @return array<string, mixed>|null
     */
    public function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$b64, $sig] = $parts;

        // Constant-time compare — never ==/=== on a signature.
        $expected = hash_hmac('sha256', $b64, $this->secret());
        if (! hash_equals($expected, $sig)) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($b64), true);
        if (! is_array($payload)) {
            return null;
        }

        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    private function secret(): string
    {
        return (string) config('services.sso.secret');
    }

    private function base64UrlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
