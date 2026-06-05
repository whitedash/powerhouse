<?php

namespace App\Enums;

/**
 * How a referral attribution was sourced — stored on
 * customer_referrals.source. Single source of truth (DB column is a plain
 * string cast to this enum).
 *
 *  - cookie : resolved from the first-party wd_ref cookie
 *  - param  : resolved from a ?ref= URL parameter
 *  - manual : a staff member attached the referral by hand
 *  - api    : ingested via POST /api/referrals/attribute (Phase 3 — reserved)
 */
enum AttributionSource: string
{
    case Cookie = 'cookie';
    case Param = 'param';
    case Manual = 'manual';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Cookie => 'Cookie',
            self::Param => 'Link param',
            self::Manual => 'Manual',
            self::Api => 'API',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s): array => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
