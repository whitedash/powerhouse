<?php

namespace App\Support;

use App\Models\FormTheme;

/**
 * Single source of truth for an embeddable form's design tokens.
 *
 * The DEFAULTS below are the embed widget's original hardcoded look — a
 * form with no theme resolves to exactly these values, so un-themed forms
 * render pixel-identically to before tokenisation. A FormTheme overrides
 * any subset of keys; absent (or null) keys fall back to the default.
 *
 * Both EmbedController and the future builder preview MUST resolve tokens
 * through here so the widget and its preview never drift.
 *
 * Token groups:
 *   - colours: text, label, accent (focus), focus_ring, background,
 *     surface (input bg), border, button bg/hover/text, error,
 *     success bg/border/text
 *   - typography: font_family, font_size (base input/button size)
 *   - shape: radius, border_width
 *   - form container: form_padding, form_border_width, form_border_radius,
 *     form_border_color (defaults are no-effect: 0 / 0 / 0 / neutral, so an
 *     existing theme renders unchanged — resolve() back-fills them)
 *   - button: button_style (solid|outline), full_width (bool)
 *   - chrome: logo_url, heading (both optional/null), custom_css (optional
 *     raw CSS injected into the shadow root AFTER the variable styles)
 */
class FormThemeTokens
{
    /**
     * The default token set == today's hardcoded widget look.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // typography
            'font_family' => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif",
            'font_size' => '14px',

            // colours
            'text' => '#1f2937',
            'label' => '#374151',
            'accent' => '#6366F1',
            'focus_ring' => 'rgba(99,102,241,0.15)',
            'background' => 'transparent',
            'surface' => '#ffffff',
            'border' => '#d1d5db',
            'button_bg' => '#0F172A',
            'button_bg_hover' => '#1f2937',
            'button_text' => '#ffffff',
            'error' => '#ef4444',
            'success_bg' => '#ecfdf5',
            'success_border' => '#10b981',
            'success_text' => '#065f46',

            // shape
            'radius' => '6px',
            'border_width' => '1px',

            // form container — defaults are NO-EFFECT (no padding / no border /
            // no radius), so existing themes + forms are pixel-identical. The
            // border colour is moot at width 0; it mirrors the field border.
            'form_padding' => '0',
            'form_border_width' => '0',
            'form_border_radius' => '0',
            'form_border_color' => '#d1d5db',

            // button behaviour
            'button_style' => 'solid', // solid | outline
            'full_width' => false,

            // optional chrome
            'logo_url' => null,
            'heading' => null,
            'custom_css' => null,
        ];
    }

    /**
     * Resolve a form's effective tokens: defaults merged with the theme's
     * overrides. Only known keys are honoured (unknown theme keys ignored);
     * a key the theme omits — or sets to null — falls back to the default.
     *
     * @return array<string, mixed>
     */
    public static function resolve(?FormTheme $theme): array
    {
        $defaults = self::defaults();

        if ($theme === null) {
            return $defaults;
        }

        /** @var array<string, mixed> $overrides */
        $overrides = $theme->tokens ?? [];

        $resolved = $defaults;
        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null) {
                $resolved[$key] = $overrides[$key];
            }
        }

        return $resolved;
    }
}
