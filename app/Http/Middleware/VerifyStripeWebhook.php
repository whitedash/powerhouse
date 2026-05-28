<?php

namespace App\Http\Middleware;

/**
 * Stripe webhook signature verification.
 *
 * Stripe's signature format (`Stripe-Signature: t=…,v1=…`) is non-trivial
 * to verify by hand: the canonical implementation is
 * `\Stripe\WebhookSignature::verifyHeader($payload, $sigHeader, $secret, $tolerance)`.
 *
 * Wire-up TODO when webhooks land:
 *   1. composer require stripe/stripe-php
 *   2. Override handle() to call WebhookSignature::verifyHeader() and
 *      throw on failure — bypass the abstract computeExpectedSignature
 *      path because Stripe needs the raw header, not a derived expected
 *      string.
 */
class VerifyStripeWebhook extends VerifyWebhookSignature
{
    protected function getSecret(): string
    {
        return (string) config('services.stripe.webhook_secret');
    }

    protected function getSignatureHeader(): string
    {
        return 'Stripe-Signature';
    }

    protected function computeExpectedSignature(string $payload, string $secret): string
    {
        // Stripe doesn't use a flat HMAC — see class docblock. Until
        // stripe/stripe-php is installed and handle() is overridden, this
        // returns an empty string so hash_equals() will always reject,
        // making the middleware fail-closed.
        return '';
    }
}
