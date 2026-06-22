<?php

namespace App\Http\Requests\Forms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Persist one step's answers onto a draft. Only the request SHAPE is validated
 * here. Per-field validation of the step's own fields runs in the service when
 * `advance` is true (a forward Next), gating the advance; save-for-later and
 * legacy clients omit `advance` and persist without per-field validation. The
 * whole-form check stays at final submit (FormController::submit).
 */
class SaveStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'step' => ['required', 'integer', 'min:1'],
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable'],
            // Opt-in per-step gate: true on a forward advance, absent/false for
            // save-for-later. Backward-compatible — old clients omit it.
            'advance' => ['nullable', 'boolean'],
        ];
    }
}
