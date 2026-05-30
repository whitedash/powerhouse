<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Services\WebhookIdempotencyService;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public form webhook receiver — POST /webhooks/{slug}.
 *
 * Signature verification happens in VerifyFormWebhookSignature
 * middleware BEFORE this controller runs (CLAUDE.md webhook rule).
 * The middleware also stashes the loaded Form on the request as
 * "verifiedForm" so we don't re-query.
 *
 * Idempotency: if the caller sends an X-Event-Id header we record
 * it via WebhookIdempotencyService and short-circuit on a replay.
 * Without that header we accept the request — many tools (Zapier,
 * curl) don't volunteer one, and we don't want to block them just
 * because we can't dedupe.
 */
class WebhookController extends Controller
{
    public function receive(string $slug, Request $request, WorkflowEngine $engine, WebhookIdempotencyService $idempotency): JsonResponse
    {
        /** @var Form|null $form */
        $form = $request->attributes->get('verifiedForm');
        if ($form === null) {
            // Belt-and-braces: middleware should have abort()ed
            // already, but a missing attribute means we slipped
            // through somehow and shouldn't process.
            abort(401);
        }

        $eventId = (string) $request->header('X-Event-Id', '');
        $source = 'form-webhook:'.$form->slug;

        if ($eventId !== '' && $idempotency->hasBeenProcessed($source, $eventId)) {
            return response()->json([
                'received' => true,
                'duplicate' => true,
            ]);
        }

        // JSON-body callers (Zapier, Make) put their payload at
        // the top level; form-encoded callers use $request->all().
        // $request->all() returns both, so it's a safe superset.
        $payload = $request->all();

        DB::transaction(function () use ($form, $payload, $request, $engine, $idempotency, $eventId, $source): void {
            $submission = FormSubmission::create([
                'form_id' => $form->id,
                'data' => $payload,
                'status' => 'new',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 65000),
                'referrer_url' => null,
            ]);

            Form::where('id', $form->id)->increment('submission_count');

            $engine->trigger(
                'webhook_received',
                array_merge($payload, [
                    'form_id' => $form->id,
                    'form_name' => $form->name,
                    'submission_id' => $submission->id,
                    'source' => $form->slug,
                ]),
                triggerEntityId: $submission->id,
            );

            FormSubmission::where('id', $submission->id)
                ->where('status', 'new')
                ->update(['status' => 'processed']);

            if ($eventId !== '') {
                $event = $idempotency->record($source, $eventId, 'webhook.received', $payload);
                $idempotency->markProcessed($event);
            }
        });

        return response()->json(['received' => true]);
    }
}
