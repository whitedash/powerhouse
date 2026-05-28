<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abstract base for every inbound webhook. Concrete subclasses tell us
 * which header carries the signature, which secret to read, and how
 * the expected signature is computed for that vendor.
 *
 * Always uses hash_equals() — never == — to prevent timing attacks.
 */
abstract class VerifyWebhookSignature
{
    abstract protected function getSecret(): string;

    abstract protected function getSignatureHeader(): string;

    abstract protected function computeExpectedSignature(string $payload, string $secret): string;

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header($this->getSignatureHeader());

        if (! $signature) {
            abort(401, 'Missing signature header');
        }

        $expected = $this->computeExpectedSignature(
            $request->getContent(),
            $this->getSecret(),
        );

        if (! hash_equals($expected, (string) $signature)) {
            Log::warning('Webhook signature mismatch', [
                'source' => class_basename(static::class),
                'ip' => $request->ip(),
            ]);

            abort(401, 'Invalid signature');
        }

        return $next($request);
    }
}
