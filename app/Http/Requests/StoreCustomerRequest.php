<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    private const PIPELINE_STAGES = ['lead', 'prospect', 'active', 'churned'];

    private const TYPE_VALUES = ['restaurant', 'bar', 'bakery', 'cafe', 'venue', 'other'];

    private const CONTACT_ROLES = ['owner', 'manager', 'accounts', 'other'];

    public function authorize(): bool
    {
        return $this->user()?->can('create', Customer::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'company_number' => ['nullable', 'string', 'max:50'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'type' => ['required', Rule::in(self::TYPE_VALUES)],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'postcode' => ['required', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'pipeline_stage' => ['nullable', Rule::in(self::PIPELINE_STAGES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email:rfc,dns', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_role' => ['nullable', Rule::in(self::CONTACT_ROLES)],
        ];
    }
}
