<?php

namespace App\Http\Requests;

use App\Models\FormTheme;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create/update a form theme from the design editor. Used for both store
 * and update. Validates the name + every design token.
 *
 * custom_css is RAW CSS injected into the widget's shadow root, so it is
 * accepted ONLY from a super_admin (Gate: manageCustomCss). For anyone
 * else the field is dropped here so it can never be persisted, and the
 * controller preserves any existing value on update.
 */
class StoreFormThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->isMethod('post') ? 'create' : 'update';

        return $this->user()?->can($ability, FormTheme::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'tokens' => ['required', 'array'],

            // Colours — kept loose (any CSS colour string) since they're
            // shadow-scoped and staff-authored; bounded length only.
            'tokens.text' => ['nullable', 'string', 'max:64'],
            'tokens.label' => ['nullable', 'string', 'max:64'],
            'tokens.accent' => ['nullable', 'string', 'max:64'],
            'tokens.focus_ring' => ['nullable', 'string', 'max:64'],
            'tokens.background' => ['nullable', 'string', 'max:64'],
            'tokens.surface' => ['nullable', 'string', 'max:64'],
            'tokens.border' => ['nullable', 'string', 'max:64'],
            'tokens.button_bg' => ['nullable', 'string', 'max:64'],
            'tokens.button_bg_hover' => ['nullable', 'string', 'max:64'],
            'tokens.button_text' => ['nullable', 'string', 'max:64'],
            'tokens.error' => ['nullable', 'string', 'max:64'],
            'tokens.success_bg' => ['nullable', 'string', 'max:64'],
            'tokens.success_border' => ['nullable', 'string', 'max:64'],
            'tokens.success_text' => ['nullable', 'string', 'max:64'],

            // Typography + shape.
            'tokens.font_family' => ['nullable', 'string', 'max:255'],
            'tokens.font_size' => ['nullable', 'string', 'max:20'],
            'tokens.radius' => ['nullable', 'string', 'max:20'],
            'tokens.border_width' => ['nullable', 'string', 'max:20'],

            // Form container styling.
            'tokens.form_padding' => ['nullable', 'string', 'max:40'],
            'tokens.form_border_width' => ['nullable', 'string', 'max:20'],
            'tokens.form_border_radius' => ['nullable', 'string', 'max:20'],
            'tokens.form_border_color' => ['nullable', 'string', 'max:64'],

            // Button behaviour.
            'tokens.button_style' => ['nullable', 'in:solid,outline'],
            'tokens.full_width' => ['nullable', 'boolean'],
            'tokens.button_hover' => ['nullable', 'in:none,lift,glow,shine,fill'],
            'tokens.button_icon' => ['nullable', 'in:none,arrow,send,chevron,check,download'],
            'tokens.button_icon_position' => ['nullable', 'in:leading,trailing'],

            // Optional chrome.
            'tokens.logo_url' => ['nullable', 'url:http,https', 'max:500'],
            'tokens.heading' => ['nullable', 'string', 'max:120'],

            // Raw CSS — validated here, but only persisted for super_admin
            // (enforced in the controller via the manageCustomCss gate).
            'tokens.custom_css' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
