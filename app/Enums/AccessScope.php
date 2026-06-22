<?php

namespace App\Enums;

/**
 * The three-way access scope a role holds for a scoped section (see
 * ScopeArea):
 *   All      — every item in the section
 *   Assigned — only items assigned to / owned by the user
 *   None     — no access
 *
 * Stored in `role_scopes.scope`. The absence of a (role, area) row is read
 * as None by the (future) resolver — the safe default that matches "a new
 * role starts blank". This enum is the single source of truth for the
 * allowed values.
 *
 * Phase 1 is INERT — the most-permissive-across-roles resolution and the
 * query mapping live in the later enforcement phase, not here.
 */
enum AccessScope: string
{
    case All = 'all';
    case Assigned = 'assigned';
    case None = 'none';

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
        return array_map(fn (self $s): string => $s->value, self::cases());
    }
}
