<?php

namespace App\Http\Requests\Forms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Initialise a multi-step form draft. Public + Portal both allowed; the
 * respondent scoping (anonymous token vs portal_user_id) lives in the
 * controller, so authorize() is open.
 */
class InitDraftRequest extends FormRequest
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
