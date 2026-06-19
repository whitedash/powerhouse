<?php

namespace App\Http\Requests\Forms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Persist one step's answers onto a draft. Only shape is validated here —
 * deep per-field validation happens at final submit (mirrors
 * FormController::submit, which validates the whole payload once on submit).
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
        ];
    }
}
