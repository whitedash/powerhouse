<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public form submit endpoint — NO auth.
 *
 * Two postures:
 *
 *   1. Anyone with the slug can post (the form is published).
 *      Spam control is best-effort because the endpoint is
 *      designed for embedding on arbitrary external sites:
 *
 *      - Honeypot: any non-empty `_hp` input means a bot filled
 *        every field. We accept the submission visually but never
 *        persist it. Telling the bot helps it learn; staying
 *        silent doesn't.
 *
 *      - Per-IP rate limit: 5/hour/slug. Below the legitimate
 *        "I made a typo, resubmitting" rate, above the bulk-fill
 *        bot rate.
 *
 *   2. JSON vs HTML: the embed snippet calls fetch() with an
 *      Accept: application/json header, so wantsJson() returns
 *      true and we respond with a small JSON envelope. A vanilla
 *      <form action> POST gets either a 302 to redirect_url or
 *      an Inertia success page.
 */
class FormController extends Controller
{
    public function submit(string $slug, Request $request, WorkflowEngine $engine): RedirectResponse|JsonResponse|InertiaResponse|Response
    {
        $form = Form::where('slug', $slug)
            ->where('status', 'active')
            ->with('fields')
            ->first();

        abort_unless($form !== null, 404);

        // Honeypot: bots fill every field; humans never touch
        // _hp because it's hidden and tabIndex=-1. Returning a
        // success to bots stops them retrying on the same UA.
        if ($request->filled('_hp')) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => true]);
            }

            return back();
        }

        // Per-IP, per-slug rate limit. 5/hour is generous for
        // humans (typo retry) and tight for bots (one form-fill
        // per request, can't burst).
        $rateKey = 'form_submit_'.$slug.'_'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $retry = RateLimiter::availableIn($rateKey);
            if ($request->wantsJson()) {
                return response()->json(
                    ['error' => 'Too many submissions. Try again later.'],
                    429,
                )->header('Retry-After', (string) $retry);
            }

            return back()->withErrors([
                'form' => 'Too many submissions. Try again later.',
            ]);
        }
        RateLimiter::hit($rateKey, 3600);

        // Field-derived validation rules. required fires when
        // the field is marked required; emails get an `email`
        // rule on top. Other validation extras can be wired in
        // from form_fields.validation_rules later.
        $rules = [];
        foreach ($form->fields as $field) {
            $chain = [];
            if ($field->is_required) {
                $chain[] = 'required';
            } else {
                $chain[] = 'nullable';
            }
            if ($field->type === 'email') {
                $chain[] = 'email:rfc';
            }
            if ($field->type === 'number') {
                $chain[] = 'numeric';
            }
            if ($field->type === 'date') {
                $chain[] = 'date';
            }
            $rules[$field->field_key] = implode('|', $chain);
        }

        // Validation failure on a JSON request returns a 422 with
        // an Inertia-shaped errors bag, same as the rest of the app.
        $validated = $request->validate($rules);

        // We persist EVERYTHING the user submitted (minus
        // framework noise) so a later form_field add still
        // captures the raw original payload.
        $payload = collect($request->except(['_token', '_hp', '_method']))
            ->filter(fn ($v) => $v !== null)
            ->all();

        DB::transaction(function () use ($form, $payload, $request, $engine): void {
            $submission = FormSubmission::create([
                'form_id' => $form->id,
                'data' => $payload,
                'status' => 'new',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 65000),
                'referrer_url' => $request->header('referer'),
            ]);

            // submission_count is denormalised on forms so the
            // Forms/Index card doesn't run COUNT(*) per row.
            Form::where('id', $form->id)->increment('submission_count');

            // Fire the engine inside the same transaction so any
            // workflow failure rolls back the submission status
            // flip below. The engine still isolates per-workflow
            // (one bad workflow doesn't break a sibling).
            $engine->trigger(
                'form_submitted',
                array_merge($payload, [
                    'form_id' => $form->id,
                    'form_name' => $form->name,
                    'submission_id' => $submission->id,
                    'ip' => $request->ip(),
                ]),
                triggerEntityId: $submission->id,
            );

            // Mark processed AFTER actions run. If a workflow
            // back-stamped lead_id via actionCreateLead the
            // status flip below is a no-op (status already
            // 'processed') — that's fine.
            FormSubmission::where('id', $submission->id)
                ->where('status', 'new')
                ->update(['status' => 'processed']);
        });

        // Redirect URL takes precedence — sites that ship their
        // own "thank you" page expect a hard navigation.
        if ($form->redirect_url !== null && $form->redirect_url !== '') {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'redirect' => $form->redirect_url,
                ]);
            }

            return redirect($form->redirect_url);
        }

        $successMessage = $form->success_message
            ?? "Thank you! We'll be in touch soon.";

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $successMessage,
            ]);
        }

        return Inertia::render('Public/FormSuccess', [
            'message' => $successMessage,
            'form_name' => $form->name,
        ]);
    }
}
