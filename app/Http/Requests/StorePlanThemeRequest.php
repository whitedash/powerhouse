<?php

namespace App\Http\Requests;

use App\Models\PlanTheme;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create/update a plan theme from the design editor — the
 * StoreFormThemeRequest shape on the plans token vocabulary. custom_css is
 * validated here but only persisted for holders of the manageCustomCss
 * gate (PlanThemeService preserves an existing value otherwise).
 */
class StorePlanThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->isMethod('post') ? 'create' : 'update';

        return $this->user()?->can($ability, PlanTheme::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tokens' => ['required', 'array'],

            // Colours — loose CSS colour strings (shadow-scoped, staff-
            // authored); the Stripe bridge separately requires hex and
            // omits anything else.
            'tokens.text' => ['nullable', 'string', 'max:64'],
            'tokens.accent' => ['nullable', 'string', 'max:64'],
            'tokens.background' => ['nullable', 'string', 'max:64'],
            'tokens.surface' => ['nullable', 'string', 'max:64'],
            'tokens.border' => ['nullable', 'string', 'max:64'],
            'tokens.button_bg' => ['nullable', 'string', 'max:64'],
            'tokens.button_bg_hover' => ['nullable', 'string', 'max:64'],
            'tokens.button_text' => ['nullable', 'string', 'max:64'],
            'tokens.error' => ['nullable', 'string', 'max:64'],
            'tokens.card_bg' => ['nullable', 'string', 'max:64'],
            'tokens.card_border' => ['nullable', 'string', 'max:64'],
            'tokens.price_color' => ['nullable', 'string', 'max:64'],
            'tokens.feature_check' => ['nullable', 'string', 'max:64'],
            'tokens.muted' => ['nullable', 'string', 'max:64'],

            // Typography + shape.
            'tokens.font_family' => ['nullable', 'string', 'max:255'],
            'tokens.font_size' => ['nullable', 'string', 'max:20'],
            'tokens.radius' => ['nullable', 'string', 'max:20'],
            'tokens.card_radius' => ['nullable', 'string', 'max:20'],
            'tokens.border_width' => ['nullable', 'string', 'max:20'],

            // Optional chrome.
            'tokens.logo_url' => ['nullable', 'url:http,https', 'max:500'],
            'tokens.heading' => ['nullable', 'string', 'max:120'],
            'tokens.custom_css' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
