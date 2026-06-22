<?php

namespace App\Enums;

/**
 * The four sections whose access is a three-way scope (All/Assigned/None)
 * rather than a simple boolean — stored per role in the `role_scopes`
 * table. This enum is the SINGLE SOURCE OF TRUTH for the area keys
 * (mirrors the PersonRole precedent: the DB column is enum-constrained and
 * the model casts to this).
 *
 * Phase 1 is INERT — nothing reads these for an authorization decision
 * yet; the resolver that maps (area, scope, user) -> query filter lands in
 * the later enforcement phase.
 */
enum ScopeArea: string
{
    case Projects = 'projects';
    case Tasks = 'tasks';
    case Leads = 'leads';
    case Support = 'support';

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
        return array_map(fn (self $a): string => $a->value, self::cases());
    }
}
