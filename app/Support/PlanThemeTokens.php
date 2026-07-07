<?php

namespace App\Support;

use App\Models\PlanTheme;
use App\Models\ProductPlan;

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

    /**
     * Resolve a PLAN's effective tokens through the override chain:
     * the plan's own theme, else its product's theme, else defaults.
     * Callers must have theme + product.theme loaded (or accept the
     * lazy-load cost).
     *
     * @return array<string, mixed>
     */
    public static function resolveForPlan(ProductPlan $plan): array
    {
        return self::resolve($plan->theme ?? $plan->product?->theme);
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

        // font_family on branding_settings is an ENUM of named fonts, NOT
        // a CSS string — sending the raw stack 400s the session creation
        // (and 500'd every plan checkout until v4 verification caught it).
        // Map the first recognisable family in the stack onto the enum;
        // no match → OMIT, same graceful posture as the hex-only colours.
        // A cosmetic mismatch must never again be able to fail a purchase.
        $stripeFont = self::stripeFontFor($tokens['font_family'] ?? null);
        if ($stripeFont !== null) {
            $branding['font_family'] = $stripeFont;
        }

        if (! empty($tokens['logo_url']) && is_string($tokens['logo_url'])) {
            $branding['logo'] = ['type' => 'url', 'url' => $tokens['logo_url']];
        }

        return $branding;
    }

    /**
     * The complete branding_settings.font_family enum for the pinned API
     * (2026-05-27.dahlia) — taken VERBATIM from the live API's own
     * invalid-value error during v4 verification and cross-checked against
     * the API reference. Normalised names ("Open Sans" → open_sans) match
     * these keys directly.
     */
    private const STRIPE_FONTS = [
        'default', 'be_vietnam_pro', 'bitter', 'chakra_petch', 'hahmlet',
        'inconsolata', 'inter', 'lato', 'lora', 'm_plus_1_code',
        'montserrat', 'noto_sans_jp', 'noto_sans', 'noto_serif', 'nunito',
        'open_sans', 'pridi', 'pt_sans', 'pt_serif', 'raleway', 'roboto',
        'roboto_slab', 'source_sans_pro', 'titillium_web', 'ubuntu_mono',
        'zen_maru_gothic',
    ];

    /**
     * First family in a CSS font stack that maps onto Stripe's named-font
     * enum, or null (→ omit). Families are matched in stack order, so
     * "Inter, sans-serif" → inter and the widget's default system stack
     * ("-apple-system,…,Roboto,sans-serif") → roboto via its fallback.
     */
    private static function stripeFontFor(mixed $stack): ?string
    {
        if (! is_string($stack) || trim($stack) === '') {
            return null;
        }

        foreach (explode(',', $stack) as $family) {
            $key = strtolower(trim($family, " \t\"'"));
            $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', $key), '_');
            if (in_array($key, self::STRIPE_FONTS, true)) {
                return $key;
            }
        }

        return null;
    }

    private static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1;
    }
}
