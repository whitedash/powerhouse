<?php

namespace App\Support;

use App\Models\PlanTheme;

/**
 * Single source of truth for the Plans widget's design tokens — the
 * FormThemeTokens mechanics, mirrored. DEFAULTS below are the widget's
 * current hardcoded look, so an un-themed product renders pixel-identical
 * to before tokenisation. resolve() merges only KNOWN keys; absent/null
 * falls back to the default.
 *
 * toStripeBranding() bridges the SAME tokens onto Checkout's session-level
 * branding_settings (API 2026-05-27) so one colour set styles both the
 * widget's own steps and the Stripe payment step. Colours there are
 * HEX-ONLY: non-hex values (rgba, transparent, keywords) are OMITTED
 * gracefully rather than rejected — the tolerant posture resolve() itself
 * takes with unknown keys, and forms has no analogous editor constraint to
 * mirror. An omitted key falls back to the account's dashboard branding.
 */
class PlanThemeTokens
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            // typography
            'font_family' => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif",
            'font_size' => '14px',

            // colours (generic — names shared with FormThemeTokens)
            'text' => '#0f172a',
            'accent' => '#0f172a',
            'background' => 'transparent',
            'surface' => '#ffffff',
            'border' => '#cbd5e1',
            'button_bg' => '#0f172a',
            'button_bg_hover' => '#0f172a', // current widget has no hover shift
            'button_text' => '#ffffff',
            'error' => '#dc2626',

            // plan-specific
            'card_bg' => '#ffffff',
            'card_border' => '#e2e8f0',
            'card_radius' => '12px',
            'price_color' => '#0f172a',
            'feature_check' => '#16a34a',
            'muted' => '#64748b',

            // shape
            'radius' => '8px',
            'border_width' => '1px',

            // optional chrome
            'logo_url' => null,
            'heading' => null,
            'custom_css' => null,
        ];
    }

    /** @return array<string, mixed> */
    public static function resolve(?PlanTheme $theme): array
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

    /**
     * Map resolved tokens onto Checkout branding_settings. border_style's
     * enum is pill|rectangular|rounded (verified against the live API
     * reference for 2026-05-27 at build time — NOT "sharp"/"square").
     *
     * @param  array<string, mixed>  $tokens  resolve() output
     * @return array<string, mixed>
     */
    public static function toStripeBranding(array $tokens): array
    {
        $branding = [];

        if (self::isHex($tokens['background'] ?? null)) {
            $branding['background_color'] = $tokens['background'];
        }
        if (self::isHex($tokens['button_bg'] ?? null)) {
            $branding['button_color'] = $tokens['button_bg'];
        }

        $radiusPx = (int) preg_replace('/[^0-9]/', '', (string) ($tokens['radius'] ?? ''));
        $branding['border_style'] = match (true) {
            $radiusPx < 4 => 'rectangular',
            $radiusPx >= 16 => 'pill',
            default => 'rounded',
        };

        if (! empty($tokens['font_family']) && is_string($tokens['font_family'])) {
            $branding['font_family'] = $tokens['font_family'];
        }
        if (! empty($tokens['logo_url']) && is_string($tokens['logo_url'])) {
            $branding['logo'] = ['type' => 'url', 'url' => $tokens['logo_url']];
        }

        return $branding;
    }

    private static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1;
    }
}
