<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\InitDraftRequest;
use App\Http\Requests\Forms\SaveStepRequest;
use App\Http\Requests\Forms\SubmitDraftRequest;
use App\Models\Form;
use App\Models\FormSubmissionDraft;
use App\Services\FormService;
use App\Services\WorkflowEngine;
use App\Support\FormFieldRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Anonymous multi-step draft endpoints — NO auth, CSRF-excluded by the
 * forms-draft rule in bootstrap/app.php, same posture as the public
 * /forms/{slug}/submit route. Resume is keyed on the draft_token the embed
 * widget stores in localStorage.
 */
class FormDraftController extends Controller
{
    public function __construct(private readonly FormService $service) {}

    public function init(string $slug, InitDraftRequest $request): JsonResponse
    {
        $form = Form::where('slug', $slug)->where('status', 'active')->firstOrFail();

        // Honeypot — accept silently, persist nothing (mirrors FormController).
        if ($request->filled('_hp')) {
            return response()->json(['ok' => true]);
        }

        // Per-slug, per-IP inline limit (same shape as the submit endpoint).
        $rateKey = 'form_draft_'.$slug.'_'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            return response()->json(['message' => 'Too many requests.'], 429);
        }
        RateLimiter::hit($rateKey, 3600);

        $draft = $this->service->initDraft($form, $request, portalUser: null);

        return response()->json([
            'draft_token' => $draft->draft_token,
            'current_step' => $draft->current_step,
            'data' => $draft->data,
        ]);
    }

    public function saveStep(string $slug, string $token, SaveStepRequest $request): JsonResponse
    {
        $form = Form::where('slug', $slug)->where('status', 'active')->with(['steps', 'fields'])->firstOrFail();

        $draft = FormSubmissionDraft::where('draft_token', $token)
            ->where('form_id', $form->id)
            ->firstOrFail();

        if ($draft->expires_at !== null && $draft->expires_at->isPast()) {
            return response()->json(['message' => 'Draft expired.'], 410);
        }

        // `advance` (opt-in, backward-compatible) runs the per-step gate; absent
        // or false (save-for-later, legacy clients) persists without validating.
        $this->service->saveStep(
            $draft,
            $form,
            $request->integer('step'),
            (array) $request->input('answers', []),
            $request,
            validateStep: $request->boolean('advance'),
        );

        return response()->json(['ok' => true, 'current_step' => $draft->current_step]);
    }

    public function submit(string $slug, string $token, SubmitDraftRequest $request, WorkflowEngine $engine): JsonResponse
    {
        $form = Form::where('slug', $slug)->where('status', 'active')->with('fields')->firstOrFail();

        $draft = FormSubmissionDraft::where('draft_token', $token)
            ->where('form_id', $form->id)
            ->firstOrFail();

        // Deep per-field validation against the full accumulated draft data,
        // using the shared FormFieldRules builder (one source of truth).
        Validator::make($draft->data ?? [], FormFieldRules::for($form->fields))->validate();

        $this->service->submitDraft($draft, $form, $request, $engine, portalUser: null);

        return response()->json([
            'ok' => true,
            'message' => $form->success_message ?? "Thank you! We'll be in touch soon.",
            'redirect' => $form->redirect_url,
        ]);
    }
}
