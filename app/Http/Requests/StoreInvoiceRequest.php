<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Invoice::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'billing_entity_id' => ['required', 'integer', 'exists:billing_entities,id'],
            'type' => ['required', Rule::in(['subscription', 'service'])],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'vat_rate' => ['required', Rule::in([0, 5, 20])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:9999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999'],
            'send_after_create' => ['nullable', 'boolean'],
        ];
    }
}
