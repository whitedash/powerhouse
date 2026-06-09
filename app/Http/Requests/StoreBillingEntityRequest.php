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
            // VAT switch + default rate. The toggle gates whether
            // the rate is even consulted; we accept any 0–100
            // figure so reduced (5%) or zero-rated entities both fit.
            'vat_registered' => ['sometimes', 'boolean'],
            'default_vat_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'postcode' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:100'],
            'bank_name' => ['required', 'string', 'max:100'],
            'sort_code' => ['required', 'string', 'max:10'],
            'account_number' => ['required', 'string', 'max:20'],
            'account_name' => ['required', 'string', 'max:100'],
            // International details — optional. Light format checks only when
            // provided: IBAN = 2 letters + 2 digits + 11–30 more (spaces
            // tolerated); BIC = 8 or 11 alphanumerics. Blank is allowed.
            'iban' => ['nullable', 'string', 'max:42', 'regex:/^[A-Za-z]{2}\d{2}[A-Za-z0-9 ]{11,34}$/'],
            'bic' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{8}([A-Za-z0-9]{3})?$/'],
            'postmark_sender_email' => ['required', 'email:rfc', 'max:255'],
            'postmark_sender_name' => ['required', 'string', 'max:100'],
            'postmark_domain' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'iban.regex' => 'That doesn\'t look like a valid IBAN (e.g. GB29NWBK60161331926819).',
            'bic.regex' => 'A BIC/SWIFT code is 8 or 11 letters and digits (e.g. NWBKGB2L).',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('country') || ! $this->country) {
            $this->merge(['country' => 'GB']);
        }
    }
}
