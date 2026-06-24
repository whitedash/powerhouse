<?php

namespace App\Traits;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use App\Support\ScopeEnforcer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Controller-side wrapper over App\Support\ScopeEnforcer for the four scoped
 * sections. Mixed into the base Controller alongside AuthorizesWithPolicy.
 *
 * Scope governs VISIBILITY (which items you can list / open). Mutation stays
 * governed by the existing policy/permission/ownership checks — these scope
 * helpers compose with, and never replace, those.
 */
trait EnforcesScope
{
    /**
     * Apply the mandatory scope filter to a list query for the current user.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopeList(Builder $query, ScopeArea $area): Builder
    {
        return ScopeEnforcer::applyToList($query, auth()->user(), $area);
    }

    /**
     * Guard a SECTION entry point: None scope = no access to the section.
     */
    protected function authorizeScopeSection(ScopeArea $area): void
    {
        if (ScopeEnforcer::effectiveScope(auth()->user(), $area) === AccessScope::None) {
            abort(403, 'You do not have access to this section.');
        }
    }

    /**
     * Guard PER-ITEM access: blocks when the item is outside the user's scope
     * (None always; Assigned when the item isn't theirs).
     */
    protected function authorizeScopeItem(ScopeArea $area, Model $item): void
    {
        abort_unless(
            ScopeEnforcer::allows(auth()->user(), $area, $item),
            403,
            'You do not have access to this item.',
        );
    }
}
