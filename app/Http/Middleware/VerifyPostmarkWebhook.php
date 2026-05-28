<?php

namespace App\Http\Middleware;

/**
 * Postmark webhook signature verification (HMAC-SHA256, base64).
 * The header value is the base64-encoded HMAC of the raw body using the
 * Postmark webhook token as the key.
 */
class VerifyPostmarkWebhook extends VerifyWebhookSignature
{
    protected function getSecret(): string
    {
        return (string) config('services.postmark.webhook_token');
    }

    protected function getSignatureHeader(): string
    {
        return 'X-Postmark-Signature';
    }

    protected function computeExpectedSignature(string $payload, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }
}
