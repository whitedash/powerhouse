<?php

namespace App\Http\Middleware;

use App\Models\Form;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-form HMAC verification for inbound webhooks.
 *
 * The stock VerifyWebhookSignature abstract assumes a single
 * vendor secret read from config — Stripe, Postmark, etc.
 * Forms break that assumption: every form row has its own
 * webhook_secret so a leaked key compromises one funnel, not
 * the whole hub. That makes a normal subclass impossible
 * (getSecret() can't see the request).
 *
 * This middleware honours the spirit of the CLAUDE.md rule
 * (class hierarchy + hash_equals + log on mismatch) while
 * pulling the secret from the request-bound Form record.
 *
 * Routing assumption: the route exposes {slug} as a parameter.
 * If the signature header is missing we 401 — this endpoint is
 * NOT a public form post (that's /forms/{slug}/submit) so
 * unsigned requests are always wrong.
 */
class VerifyFormWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('slug');

        $form = Form::where('slug', $slug)
            ->where('status', 'active')
            ->first();

        if (! $form) {
            abort(404);
        }

        $signature = (string) $request->header('X-Webhook-Signature', '');

        if ($signature === '') {
            abort(401, 'Missing signature header');
        }

        $expected = 'sha256='.hash_hmac(
            'sha256',
            $request->getContent(),
            $form->webhook_secret,
        );

        if (! hash_equals($expected, $signature)) {
            Log::warning('Form webhook signature mismatch', [
                'form_id' => $form->id,
                'slug' => $form->slug,
                'ip' => $request->ip(),
            ]);

            abort(401, 'Invalid signature');
        }

        // Hand the form object to the controller so it doesn't
        // re-query the same row — bound under "verifiedForm" to
        // avoid colliding with any route-bound model.
        $request->attributes->set('verifiedForm', $form);

        return $next($request);
    }
}
