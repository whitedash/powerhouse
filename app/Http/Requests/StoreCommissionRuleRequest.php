<?php

namespace App\Http\Requests;

use App\Models\CommissionRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Create/update a commission rule from the admin UI. Fields map 1:1 to the
 * calculator's config contract via friendly inputs; CommissionRuleService
 * translates them into type + config. Used for both store and update.
 */
class StoreCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CommissionRule::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'referrer_id' => ['nullable', 'integer', 'exists:referrers,id'],
            'mode' => ['required', 'in:one_off,recurring'],

            // One-off: percentage OR flat (rate_kind decides which is required).
            'rate_kind' => ['nullable', 'required_if:mode,one_off', 'in:percentage,flat'],
            'percentage' => ['required_if:rate_kind,percentage', 'nullable', 'numeric', 'between:0,100'],
            'flat_amount' => ['required_if:rate_kind,flat', 'nullable', 'numeric', 'min:0'],

            // Recurring: a recurring percentage + a duration.
            'recurring_percentage' => ['required_if:mode,recurring', 'nullable', 'numeric', 'between:0,100'],
            'duration' => ['nullable', 'required_if:mode,recurring', 'in:lifetime,months'],
            'recurring_months' => ['required_if:duration,months', 'nullable', 'integer', 'min:1'],

            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Belt-and-braces: at least one rate must carry a meaningful value
            // so the UI can never persist config the engine reads as zero.
            $hasRate = (float) ($this->input('percentage') ?? 0) > 0
                || (float) ($this->input('flat_amount') ?? 0) > 0
                || (float) ($this->input('recurring_percentage') ?? 0) > 0;

            if (! $hasRate) {
                $v->errors()->add('percentage', 'Set a percentage, a flat amount, or a recurring percentage.');
            }
        });
    }
}
