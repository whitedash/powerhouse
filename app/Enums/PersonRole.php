<?php

namespace App\Enums;

/**
 * The capacity in which a person is associated with a company, stored on
 * the `customer_person` pivot. This enum is the SINGLE SOURCE OF TRUTH
 * for the allowed values — the DB column is a plain string, validation
 * uses Rule::enum(self::class), and the pivot model casts to it. There is
 * deliberately no parallel DB enum or controller const to drift from.
 */
enum PersonRole: string
{
    case Owner = 'owner';
    case Director = 'director';
    case Shareholder = 'shareholder';
    case Partner = 'partner';
    case Manager = 'manager';
    case Accounts = 'accounts';
    case Signatory = 'signatory';
    case Other = 'other';

    /**
     * Human-readable label for UI (selects, chips). Most cases are just
     * the title-cased value, so we special-case nothing yet — kept as a
     * method so future multi-word roles have one place to live.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Backing values, for validation rules and front-end option lists.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $r): string => $r->value, self::cases());
    }

    /**
     * Value/label option pairs for building front-end <select> lists.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $r): array => ['value' => $r->value, 'label' => $r->label()],
            self::cases(),
        );
    }
}
