<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe webhook signature verification.
 *
 * Stripe's signature format (`Stripe-Signature: t=…,v1=…`) is not a flat
 * HMAC, so handle() is overridden to call the canonical
 * `\Stripe\WebhookSignature::verifyHeader()` rather than the base class's
 * computeExpectedSignature/hash_equals path. Fails closed: a missing
 * secret, missing header, or any verification error aborts with 401.
 */
class VerifyStripeWebhook extends VerifyWebhookSignature
{
    /** Reject events whose timestamp is more than 5 minutes old (replay guard). */
    private const TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->getSecret();
        $signature = $request->header($this->getSignatureHeader());

        if ($secret === '' || ! $signature) {
            abort(401, 'Missing signature header');
        }

        try {
            WebhookSignature::verifyHeader(
                $request->getContent(),
                (string) $signature,
                $secret,
                self::TOLERANCE_SECONDS,
            );
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature mismatch', [
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            abort(401, 'Invalid signature');
        }

        return $next($request);
    }

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
        // Unused — handle() is overridden. Returns empty so the base
        // path stays fail-closed if it is ever reached.
        return '';
    }
}
