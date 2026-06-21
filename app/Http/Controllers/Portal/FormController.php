<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\InitDraftRequest;
use App\Http\Requests\Forms\SaveStepRequest;
use App\Http\Requests\Forms\SubmitDraftRequest;
use App\Models\Form;
use App\Models\FormSubmissionDraft;
use App\Models\PortalUser;
use App\Services\FormService;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Authenticated Portal respondent flow for a multi-step form. Drafts resume by
 * portal_user_id (no token round-trip). Every query is scoped to the portal
 * user — findOrFail 404s anything that isn't theirs (same pattern as the rest
 * of the Portal area).
 */
class FormController extends Controller
{
    public function __construct(private readonly FormService $service) {}

    public function show(int $id, Request $request): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $form = Form::where('id', $id)
            ->where('status', 'active')
            ->with(['fields', 'steps'])
            ->firstOrFail();

        // Live draft for this respondent (non-expired). The expiry test is
        // grouped so it doesn't leak past the portal_user_id scope.
        $draft = FormSubmissionDraft::where('form_id', $form->id)
            ->where('portal_user_id', $portalUser->id)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        return Inertia::render('Portal/Forms/Fill', [
            'form' => $form->load('steps', 'fields'),
            'draft' => $draft !== null
                ? ['current_step' => $draft->current_step, 'data' => $draft->data]
                : null,
        ]);
    }

    public function initDraft(int $id, InitDraftRequest $request): JsonResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $form = Form::where('id', $id)->where('status', 'active')->firstOrFail();

        $draft = $this->service->initDraft($form, $request, portalUser: $portalUser);

        // No draft_token for authenticated users — they resume by portal_user_id.
        return response()->json(['current_step' => $draft->current_step, 'data' => $draft->data]);
    }

    public function saveStep(int $id, SaveStepRequest $request): JsonResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $form = Form::where('id', $id)->where('status', 'active')->firstOrFail();

        $draft = FormSubmissionDraft::where('form_id', $form->id)
            ->where('portal_user_id', $portalUser->id)
            ->firstOrFail();

        $this->service->saveStep(
            $draft,
            $request->integer('step'),
            (array) $request->input('answers', []),
            $request,
        );

        return response()->json(['ok' => true, 'current_step' => $draft->current_step]);
    }

    public function submit(int $id, SubmitDraftRequest $request, WorkflowEngine $engine): JsonResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $form = Form::where('id', $id)->where('status', 'active')->with('fields')->firstOrFail();

        $draft = FormSubmissionDraft::where('form_id', $form->id)
            ->where('portal_user_id', $portalUser->id)
            ->firstOrFail();

        Validator::make($draft->data ?? [], $this->fieldRules($form))->validate();

        $this->service->submitDraft($draft, $form, $request, $engine, portalUser: $portalUser);

        return response()->json([
            'ok' => true,
            'message' => $form->success_message ?? "Thank you! We'll be in touch soon.",
            'redirect' => $form->redirect_url,
        ]);
    }

    /**
     * Per-field validation rules from the form's fields — identical to
     * FormController::submit / FormDraftController::submit.
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
