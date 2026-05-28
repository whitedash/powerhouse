<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    private const PIPELINE_STAGES = ['lead', 'prospect', 'active', 'churned'];

    private const TYPE_VALUES = ['restaurant', 'bar', 'bakery', 'cafe', 'venue', 'other'];

    public function authorize(): bool
    {
        $customer = $this->route('customer') ?? $this->route('id');

        return $this->user()?->can('update', $customer instanceof Customer ? $customer : Customer::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'company_number' => ['nullable', 'string', 'max:50'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'type' => ['sometimes', 'required', Rule::in(self::TYPE_VALUES)],
            'address_line1' => ['sometimes', 'required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:120'],
            'postcode' => ['sometimes', 'required', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'pipeline_stage' => ['nullable', Rule::in(self::PIPELINE_STAGES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
