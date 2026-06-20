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
        $form = Form::where('slug', $slug)->where('status', 'active')->firstOrFail();

        $draft = FormSubmissionDraft::where('draft_token', $token)
            ->where('form_id', $form->id)
            ->firstOrFail();

        if ($draft->expires_at !== null && $draft->expires_at->isPast()) {
            return response()->json(['message' => 'Draft expired.'], 410);
        }

        $this->service->saveStep(
            $draft,
            $request->integer('step'),
            (array) $request->input('answers', []),
            $request,
        );

        return response()->json(['ok' => true, 'current_step' => $draft->current_step]);
    }

    public function submit(string $slug, string $token, SubmitDraftRequest $request, WorkflowEngine $engine): JsonResponse
    {
        $form = Form::where('slug', $slug)->where('status', 'active')->with('fields')->firstOrFail();

        $draft = FormSubmissionDraft::where('draft_token', $token)
            ->where('form_id', $form->id)
            ->firstOrFail();

        // Deep per-field validation against the full accumulated draft data —
        // the same rule-building logic FormController::submit applies once.
        Validator::make($draft->data ?? [], $this->fieldRules($form))->validate();

        $this->service->submitDraft($draft, $form, $request, $engine, portalUser: null);

        return response()->json([
            'ok' => true,
            'message' => $form->success_message ?? "Thank you! We'll be in touch soon.",
            'redirect' => $form->redirect_url,
        ]);
    }

    /**
     * Build per-field validation rules from the form's fields (required/email/
     * numeric/date), keyed by field_key — identical to FormController::submit.
     *
     * @return array<string, string>
     */
    private function fieldRules(Form $form): array
    {
        $rules = [];

        foreach ($form->fields as $field) {
            // Placeholder fields are display-only — no input, no rule.
            if ($field->type === 'placeholder') {
                continue;
            }
            $chain = [$field->is_required ? 'required' : 'nullable'];
            if ($field->type === 'email') {
                $chain[] = 'email:rfc';
            }
            if ($field->type === 'number') {
                $chain[] = 'numeric';
            }
            if ($field->type === 'date' || $field->type === 'datetime') {
                $chain[] = 'date';
            }
            $rules[$field->field_key] = implode('|', $chain);
        }

        return $rules;
    }
}
