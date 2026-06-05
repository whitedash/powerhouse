<?php

namespace App\Http\Requests;

use App\Enums\PersonRole;
use App\Models\Person;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates attaching a person to a company AND changing the role on an
 * existing association — both carry the same payload (customer_id + role
 * + optional job_title).
 */
class AttachPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Managing a person's company associations is an "update person"
        // action; the controller separately confirms the customer is
        // viewable/editable.
        return $this->user()?->can('update', Person::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'role' => ['required', Rule::enum(PersonRole::class)],
            'job_title' => ['nullable', 'string', 'max:100'],
        ];
    }
}
