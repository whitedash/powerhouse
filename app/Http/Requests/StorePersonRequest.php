<?php

namespace App\Http\Requests;

use App\Enums\PersonRole;
use App\Models\Person;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Person::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // People-level email is globally unique (it's the dedupe key
            // for the cross-company identity). nullable so an email-less
            // person is still valid.
            'email' => ['nullable', 'email', 'max:255', 'unique:people,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Optional attach-on-create: lets the customer page create a
            // person AND link them to that company in one request. When
            // attach_customer_id is present, attach_role is required.
            'attach_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'attach_role' => ['nullable', 'required_with:attach_customer_id', Rule::enum(PersonRole::class)],
            'attach_job_title' => ['nullable', 'string', 'max:100'],
        ];
    }
}
