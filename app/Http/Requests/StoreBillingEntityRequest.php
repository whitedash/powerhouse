<?php

namespace App\Http\Requests;

use App\Models\BillingEntity;
use Illuminate\Foundation\Http\FormRequest;

class StoreBillingEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = $this->route('id')
            ? BillingEntity::find($this->route('id'))
            : null;

        return $entity
            ? ($this->user()?->can('update', $entity) ?? false)
            : ($this->user()?->can('create', BillingEntity::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['required', 'string', 'max:255'],
            'company_number' => ['required', 'string', 'max:50'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'postcode' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:100'],
            'bank_name' => ['required', 'string', 'max:100'],
            'sort_code' => ['required', 'string', 'max:10'],
            'account_number' => ['required', 'string', 'max:20'],
            'account_name' => ['required', 'string', 'max:100'],
            'postmark_sender_email' => ['required', 'email:rfc', 'max:255'],
            'postmark_sender_name' => ['required', 'string', 'max:100'],
            'postmark_domain' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('country') || ! $this->country) {
            $this->merge(['country' => 'GB']);
        }
    }
}
