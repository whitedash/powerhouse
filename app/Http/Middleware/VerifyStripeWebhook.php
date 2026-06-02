<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe webhook signature verification.
 *
 * Stripe's signature format (`Stripe-Signature: t=…,v1=…`) is not a flat
 * HMAC, so we can't use the parent's computeExpectedSignature/hash_equals
 * path. Instead we override handle() to call the canonical SDK verifier,
 * `\Stripe\Webhook::constructEvent()`, which performs the timestamped,
 * constant-time signature check internally and rejects replays outside the
 * default tolerance window.
 *
 * On success the parsed, trusted Stripe\Event is stashed on the request as
 * "stripeEvent" so the controller never re-parses the raw body (mirrors how
 * VerifyFormWebhookSignature hands the controller a "verifiedForm").
 */
class VerifyStripeWebhook extends VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->getSecret();

        // Fail closed if the endpoint secret was never configured — an
        // unsigned-but-accepted webhook is a forgery surface.
        if ($secret === '') {
            Log::warning('Stripe webhook rejected: no webhook secret configured');
            abort(401, 'Webhook secret not configured');
        }

        $signature = $request->header($this->getSignatureHeader());
        if (! $signature) {
            abort(401, 'Missing signature header');
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $signature,
                $secret,
            );
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature mismatch', [
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);
            abort(401, 'Invalid signature');
        } catch (\UnexpectedValueException $e) {
            // Malformed JSON payload.
            abort(400, 'Invalid payload');
        }

        $request->attributes->set('stripeEvent', $event);

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
        // Unused — handle() is overridden above. Kept as a fail-closed
        // stub so the abstract contract is satisfied and any accidental
        // fallback to the parent path rejects every request.
        return '';
    }
}
