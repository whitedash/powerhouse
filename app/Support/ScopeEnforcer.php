<?php

namespace App\Support;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use App\Models\Lead;
use App\Models\Project;
use App\Models\RoleScope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * Shared scope-enforcement mechanism for the four scoped sections
 * (Projects/Tasks/Leads/Support). Phase 3b-i builds it and wires Projects;
 * the next three areas plug into the per-area seam (assignedListFilter /
 * assignedItemAllows match arms) without touching the resolution logic.
 *
 * THREE jobs:
 *   - effectiveScope(): a user's scope for an area = MOST-PERMISSIVE across
 *     their Spatie roles' role_scopes (All > Assigned > None). super_admin is
 *     ALWAYS All — returned here explicitly, because a query-level filter is
 *     NOT intercepted by the Gate::before super-admin bypass (that only
 *     covers gate/policy checks). Absence of any row = None (safe default).
 *   - applyToList(): mandatory list filter. All → unchanged; None → empty;
 *     Assigned → the area's ownership filter.
 *   - allows(): per-item visibility. All → true; None → false; Assigned →
 *     the area's ownership predicate on that item.
 *
 * The trait App\Traits\EnforcesScope wraps these for controllers (pulling the
 * auth user and turning None / off-scope into a 403).
 */
class ScopeEnforcer
{
    public static function effectiveScope(?Authenticatable $user, ScopeArea $area): AccessScope
    {
        if (! $user instanceof User) {
            return AccessScope::None;
        }

        // super_admin sees everything — explicitly, since query filters are
        // not covered by Gate::before.
        if ($user->isSuperAdmin()) {
            return AccessScope::All;
        }

        // Relation QUERY (not the loaded collection) so we never trip
        // preventLazyLoading.
        $roleIds = $user->roles()->pluck('roles.id');
        if ($roleIds->isEmpty()) {
            return AccessScope::None;
        }

        $scopes = RoleScope::query()
            ->whereIn('role_id', $roleIds)
            ->where('area', $area->value)
            ->get()
            ->pluck('scope'); // collection<AccessScope> (model cast applied)

        return match (true) {
            $scopes->contains(AccessScope::All) => AccessScope::All,
            $scopes->contains(AccessScope::Assigned) => AccessScope::Assigned,
            default => AccessScope::None,
        };
    }

    /**
     * Apply the mandatory scope filter to a list query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyToList(Builder $query, ?Authenticatable $user, ScopeArea $area): Builder
    {
        $scope = self::effectiveScope($user, $area);

        if ($scope === AccessScope::All) {
            return $query;
        }

        if ($scope === AccessScope::None || ! $user instanceof User) {
            return $query->whereRaw('1 = 0'); // matches nothing
        }

        return self::assignedListFilter($query, $area, (int) $user->id);
    }

    /**
     * Per-item visibility under the user's effective scope.
     */
    public static function allows(?Authenticatable $user, ScopeArea $area, Model $item): bool
    {
        $scope = self::effectiveScope($user, $area);

        if ($scope === AccessScope::All) {
            return true;
        }

        if ($scope === AccessScope::None || ! $user instanceof User) {
            return false;
        }

        return self::assignedItemAllows($area, $item, (int) $user->id);
    }

    /**
     * Apply the mandatory scope to an EAGER-LOAD relation — the project board's
     * tasks, a customer's / lead's activity list, a task's child tasks. Those
     * surfaces never hit applyToList(): the tasks ride in on a parent's
     * ->with(), so the filter has to attach to the relation, not a top-level
     * list query. Same three-way behaviour as applyToList (All → untouched;
     * None → empty; Assigned → the area filter) applied to the relation's
     * underlying Eloquent builder, which getQuery() exposes. Mutates in place.
     *
     * super_admin resolves to All HERE, not via Gate::before — that bypass
     * covers gate/policy checks, never eager-load queries, so an unscoped admin
     * would otherwise see a filtered board.
     *
     * @param  Relation<Model, Model, *>  $relation
     */
    public static function constrainRelation(Relation $relation, ?Authenticatable $user, ScopeArea $area): void
    {
        $scope = self::effectiveScope($user, $area);

        if ($scope === AccessScope::All) {
            return;
        }

        if ($scope === AccessScope::None || ! $user instanceof User) {
            $relation->whereRaw('1 = 0'); // load nothing

            return;
        }

        // Same per-area expression the list path uses — applied to the
        // relation's query so the seam stays the single source of truth.
        self::assignedListFilter($relation->getQuery(), $area, (int) $user->id);
    }

    /**
     * PER-AREA SEAM — the "Assigned" list filter. Projects = pivot membership;
     * Tasks / Leads = own assignment. (Support adds its arm in the last phase.)
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function assignedListFilter(Builder $query, ScopeArea $area, int $userId): Builder
    {
        return match ($area) {
            ScopeArea::Projects => $query->whereHas('members', fn ($q) => $q->where('user_id', $userId)),
            // Tasks: "Assigned" is STRICTLY the assignee — deliberately NOT
            // created_by. Existing ownership checks (which allow creator OR
            // assignee to mutate) compose on TOP of this; a task you created
            // but delegated is invisible under Assigned scope.
            ScopeArea::Tasks => $query->where('assigned_to', $userId),
            // Leads: "Assigned" is the assignee (leads.assigned_to, a NULLABLE
            // FK). The = comparison excludes NULL rows, so an Assigned-scoped
            // user sees NEITHER other people's leads NOR unassigned ones — a
            // lead assigned to nobody is, deliberately, not "mine". (Leads has
            // no "view unassigned" composition permission, unlike Support.)
            ScopeArea::Leads => $query->where('assigned_to', $userId),
            default => throw new LogicException("No Assigned-scope list filter registered for area: {$area->value}"),
        };
    }

    /**
     * PER-AREA SEAM — the "Assigned" per-item predicate (same ownership rule
     * as assignedListFilter, evaluated against one model).
     */
    private static function assignedItemAllows(ScopeArea $area, Model $item, int $userId): bool
    {
        return match ($area) {
            ScopeArea::Projects => $item instanceof Project
                && $item->members()->where('user_id', $userId)->exists(),
            // Mirror of the Tasks list filter: strictly the assignee.
            ScopeArea::Tasks => $item instanceof Task
                && $item->assigned_to === $userId,
            // Mirror of the Leads list filter: strictly the assignee. A
            // NULL-assigned lead fails the === comparison, so it's not "mine".
            ScopeArea::Leads => $item instanceof Lead
                && $item->assigned_to === $userId,
            default => throw new LogicException("No Assigned-scope item check registered for area: {$area->value}"),
        };
    }
}
