<?php

namespace App\Enums;

/**
 * Layout width of a single form field within the embed widget's 12-column
 * grid. Lives on the FIELD (per-form structure), not on a theme.
 *
 *  - full  : spans the whole row (12/12) — the default, == today's stack
 *  - half  : 6/12 — two per row
 *  - third : 4/12 — three per row
 *
 * The grid collapses to single-column on narrow CONTAINERS (not viewports)
 * via CSS container queries in the widget, so half/third fall back to full
 * on small embeds regardless of these spans.
 */
enum FieldWidth: string
{
    case Full = 'full';
    case Half = 'half';
    case Third = 'third';

    /**
     * Column span on the widget's 12-column grid.
     */
    public function cols(): int
    {
        return match ($this) {
            self::Full => 12,
            self::Half => 6,
            self::Third => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full',
            self::Half => 'Half',
            self::Third => 'Third',
        };
    }
}
