<?php

namespace App\Http\Requests\Forms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Promote a draft to a submission. The draft already carries every answer, so
 * no request body is needed — per-field validation runs against the draft data
 * in the controller before the service promotes it.
 */
class SubmitDraftRequest extends FormRequest
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
        return [];
    }
}
