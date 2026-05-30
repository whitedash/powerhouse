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
            'lines.*.id' => ['nullable', 'integer', 'exists:invoice_lines,id'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:9999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999'],
            // Optional product / plan attribution per line — feeds the
            // per-product revenue reporting and the line badge that
            // makes "this is a Maavelus charge" readable at a glance.
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.plan_id' => ['nullable', 'integer', 'exists:product_plans,id'],
            // Line-level discount (FIX 6). discount_type tells the
            // server to read discount_value as a percentage (0–100)
            // or a fixed £ amount; we recompute the cooked
            // discount_amount + net amount in the controller so the
            // client can't smuggle a bigger discount than maths allows.
            'lines.*.discount_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            // Recurring template fields. is_recurring opt-in; when on,
            // the three recurring_* fields gate together so we don't
            // accept "recurring but no schedule" half-states.
            'is_recurring' => ['nullable', 'boolean'],
            'recurring_interval_count' => ['nullable', 'required_if:is_recurring,true', 'integer', 'min:1', 'max:24'],
            'recurring_interval_unit' => ['nullable', 'required_if:is_recurring,true', Rule::in(['week', 'month', 'year'])],
            'recurring_ends_at' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'send_after_create' => ['nullable', 'boolean'],
        ];
    }
}
