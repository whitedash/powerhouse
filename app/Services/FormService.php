<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\FormSubmissionDraft;
use App\Models\PortalUser;
use App\Support\FormFieldRules;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Owns all multi-step draft + submission-promotion logic. Controllers call
 * into here and hold no business logic of their own.
 *
 * Draft lifecycle:
 *   initDraft  → create (or resume) a form_submission_drafts row
 *   saveStep   → merge a step's answers; NEVER fires the WorkflowEngine
 *   submitDraft→ promote to a form_submissions row (status 'new', which fires
 *                the engine exactly as a single-shot submit would), then delete
 *                the draft — all in one transaction.
 */
class FormService
{
    public function initDraft(Form $form, Request $request, ?PortalUser $portalUser = null): FormSubmissionDraft
    {
        return DB::transaction(function () use ($form, $request, $portalUser): FormSubmissionDraft {
            // Authenticated resume: never create a second draft for the same
            // (form, portal user) while a live one exists.
            if ($portalUser !== null) {
                $existing = FormSubmissionDraft::query()
                    ->where('form_id', $form->id)
                    ->where('portal_user_id', $portalUser->id)
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            // draft_token needs the row id, so it is set empty here and filled
            // in immediately after insert (mirrors the proposals token pattern).
            $draft = FormSubmissionDraft::create([
                'form_id' => $form->id,
                'draft_token' => '',
                'portal_user_id' => $portalUser?->id,
                'current_step' => 0,
                'data' => [],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'referrer_url' => $request->header('referer'),
                // Anonymous drafts expire; authenticated drafts persist.
                'expires_at' => $portalUser === null ? now()->addDays(7) : null,
            ]);

            $draft->update(['draft_token' => FormSubmissionDraft::generateToken($draft->id)]);

            ActivityLog::create([
                'user_id' => $portalUser?->id,
                'user_role' => $portalUser !== null ? 'portal' : null,
                'action' => 'form.draft.started',
                'entity_type' => 'form',
                'entity_id' => $form->id,
                'ip_address' => $request->ip(),
            ]);

            return $draft;
        });
    }

    /**
     * Persist one step's answers onto a draft, then (on a forward advance only)
     * run the per-step gate: validate ONLY the fields on the step being LEFT.
     *
     * Order is persist-THEN-validate so a blocked advance keeps the respondent's
     * input — the answers are saved before the gate can throw. On a failed gate
     * the resume marker (current_step) is NOT bumped, so resume returns to the
     * step that has errors. $validateStep is false for save-for-later and for any
     * legacy client that doesn't opt in, so their behaviour is unchanged.
     *
     * @param  array<string, mixed>  $answers
     */
    public function saveStep(FormSubmissionDraft $draft, Form $form, int $step, array $answers, Request $request, bool $validateStep = false): FormSubmissionDraft
    {
        abort_if($step < 1, 422, 'Step must be greater than zero.');

        // Persist FIRST — a blocked advance must preserve the respondent's input.
        $merged = array_merge($draft->data ?? [], $answers);
        $draft->update(['data' => $merged, 'updated_at' => now()]);

        // Per-step gate (forward advance only): validate ONLY the fields on the
        // step being left, with the SAME FormFieldRules the final submit uses, so
        // the gate and the backstop can't drift. A 422 here (field-keyed errors)
        // blocks the advance; legacy single-step forms have no step rows, so the
        // field set is empty and nothing is gated.
        if ($validateStep) {
            $stepFields = $this->fieldsForStep($form, $step);
            if ($stepFields->isNotEmpty()) {
                Validator::make($merged, FormFieldRules::for($stepFields))->validate();
            }
        }

        // Advance the resume marker only once the step is clean (or wasn't gated).
        $draft->update(['current_step' => max($draft->current_step, $step)]);

        return $draft;
    }

    /**
     * The fields on the step being LEFT during a forward advance. The client
     * sends `step` as the 1-based index of the NEXT step it is moving to
     * (currentStep + 1), so the step being validated is the (step - 1)-th step
     * ordered by sort_order. Out-of-range or single-step forms (no step rows)
     * yield an empty set, so nothing is gated.
     *
     * @return Collection<int, FormField>
     */
    private function fieldsForStep(Form $form, int $step): Collection
    {
        $departingStep = $form->steps->values()->get($step - 1);

        if ($departingStep === null) {
            return collect();
        }

        return $form->fields->where('form_step_id', $departingStep->id)->values();
    }

    public function submitDraft(FormSubmissionDraft $draft, Form $form, Request $request, WorkflowEngine $engine, ?PortalUser $portalUser = null): FormSubmission
    {
        abort_if($draft->form_id !== $form->id, 403);
        abort_if($draft->expires_at !== null && $draft->expires_at->isPast(), 410);

        $submission = DB::transaction(function () use ($draft, $form, $engine): FormSubmission {
            // 1. Persist the completed submission FIRST (forensic trace).
            $submission = FormSubmission::create([
                'form_id' => $form->id,
                'data' => $draft->data ?? [],
                'status' => 'new',
                'ip_address' => $draft->ip_address,
                'user_agent' => $draft->user_agent,
                'referrer_url' => $draft->referrer_url,
            ]);

            // 2. Bump the denormalised counter.
            Form::where('id', $form->id)->increment('submission_count');

            // 3. Fire the engine inside this transaction — same contract as the
            //    single-shot FormController::submit (referral attribution is not
            //    resolved for drafts, so referrer_id/referral_code are null).
            $engine->trigger(
                'form_submitted',
                array_merge($draft->data ?? [], [
                    'form_id' => $form->id,
                    'form_name' => $form->name,
                    'submission_id' => $submission->id,
                    'ip' => $draft->ip_address,
                    'referrer_id' => null,
                    'referral_code' => null,
                ]),
                triggerEntityId: $submission->id,
            );

            // 4. Mark processed if the engine left it untouched.
            FormSubmission::where('id', $submission->id)
                ->where('status', 'new')
                ->update(['status' => 'processed']);

            // The draft has served its purpose — remove it in the same tx.
            $draft->delete();

            return $submission;
        });

        ActivityLog::create([
            'user_id' => $portalUser?->id,
            'user_role' => $portalUser !== null ? 'portal' : null,
            'action' => 'form.submitted',
            'entity_type' => 'form',
            'entity_id' => $form->id,
            'ip_address' => $request->ip(),
            'after' => ['submission_id' => $submission->id],
        ]);

        return $submission;
    }
}
