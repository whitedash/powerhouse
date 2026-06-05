<?php

namespace App\Enums;

/**
 * Stable product identifiers used by the referral layer — the `p` param
 * on /r/{code}, the redirect destination map (config/referrals.php), and
 * the product column on referral_clicks / customer_referrals.
 *
 * This is the referral-side product vocabulary; it intentionally lives
 * apart from the products table (which is data-driven) so a marketing
 * link can target a product family without a DB lookup.
 */
enum ProductKey: string
{
    case Maavelus = 'maavelus';
    case OrderPad = 'orderpad';
    case Whitedash = 'whitedash';
    case Estia = 'estia';
    case Comnicube = 'comnicube';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Maavelus => 'Maavelus',
            self::OrderPad => 'MyOrderPad',
            self::Whitedash => 'Whitedash',
            self::Estia => 'Estia',
            self::Comnicube => 'Comnicube',
            self::Other => 'Other',
        };
    }

    /**
     * Safe parse: returns null for unknown/empty input so the redirect can
     * ignore an unrecognised `p` param rather than erroring.
     */
    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $k): string => $k->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $k): array => ['value' => $k->value, 'label' => $k->label()], self::cases());
    }
}
