<?php

namespace App\Http\Controllers\Internal;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetRoleScopeRequest;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\ToggleRolePermissionRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\ActivityLog;
use App\Models\RoleScope;
use App\Support\PermissionMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Roles & permissions matrix admin (Settings → Roles & permissions).
 *
 * PHASE 2 — this screen WRITES the Spatie roles/permissions and role_scopes
 * data, but NOTHING new reads it for an authorization decision. The app
 * still gates entirely on the users.role enum (isStaff/isSuperAdmin). The
 * screen itself is gated by the EXISTING role:super_admin route group (see
 * routes/web.php) — no new enforcement is introduced here.
 *
 * super_admin is never editable here: it bypasses the matrix (and the bypass
 * itself is a phase-3 concern). `staff` is a protected system role — it can
 * be reconfigured but not renamed or deleted.
 */
class RoleController extends Controller
{
    /** Roles that exist in the matrix data but are never editable/removable. */
    private const SYSTEM_ROLES = ['super_admin', 'staff'];

    private const GUARD = 'web';

    public function index(): Response
    {
        $scopesByRole = RoleScope::all()->groupBy('role_id');

        $roles = Role::query()
            ->where('guard_name', self::GUARD)
            ->where('name', '!=', 'super_admin')
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'is_system' => in_array($role->name, self::SYSTEM_ROLES, true),
                'user_count' => $role->users_count,
                'permissions' => $role->permissions->pluck('name')->all(),
                'scopes' => $this->scopesFor($scopesByRole->get($role->id)),
            ])
            ->values()
            ->all();

        return Inertia::render('Internal/Settings/Roles', [
            'roles' => $roles,
            'groups' => PermissionMatrix::groups(),
            'areas' => ScopeArea::values(),
            'scopeOptions' => AccessScope::values(),
            // Roles a new role can be copied from (everything in the matrix).
            'copyableRoles' => collect($roles)->map(fn ($r): array => ['id' => $r['id'], 'name' => $r['name']])->all(),
            'superAdminNote' => 'Super Admin bypasses all permissions — always full access, never lockable, and not configurable in this matrix.',
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $role = DB::transaction(function () use ($data, $request): Role {
            /** @var Role $role */
            $role = Role::create(['name' => $data['name'], 'guard_name' => self::GUARD]);

            // "Copy from" — one-time seed of the source role's COMPLETE state:
            // every boolean permission (incl. support.view_unassigned) AND all
            // four scope values. Not a live link; later edits don't propagate.
            if (($data['mode'] ?? 'blank') === 'copy' && ! empty($data['copy_from_id'])) {
                $source = Role::where('guard_name', self::GUARD)
                    ->where('name', '!=', 'super_admin')
                    ->findOrFail($data['copy_from_id']);

                $role->syncPermissions($source->permissions);

                foreach (RoleScope::where('role_id', $source->id)->get() as $scope) {
                    RoleScope::create([
                        'role_id' => $role->id,
                        'area' => $scope->area->value,
                        'scope' => $scope->scope->value,
                    ]);
                }
            }

            $this->log($request, 'role.created', $role->id, after: [
                'name' => $role->name,
                'copied_from' => $data['copy_from_id'] ?? null,
            ]);

            return $role;
        });

        return back()->with('success', "Role '{$role->name}' created.");
    }

    public function update(int $id, UpdateRoleRequest $request): RedirectResponse
    {
        $role = $this->editableRole($id);

        if (in_array($role->name, self::SYSTEM_ROLES, true)) {
            return back()->with('error', 'System roles (super_admin, staff) cannot be renamed.');
        }

        $data = $request->validated();

        DB::transaction(function () use ($role, $data, $request): void {
            $before = ['name' => $role->name];
            $role->update(['name' => $data['name']]);
            $this->log($request, 'role.renamed', $role->id, $before, ['name' => $data['name']]);
        });

        return back()->with('success', 'Role renamed.');
    }

    public function destroy(int $id, Request $request): RedirectResponse
    {
        $role = $this->editableRole($id);

        if (in_array($role->name, self::SYSTEM_ROLES, true)) {
            return back()->with('error', 'System roles (super_admin, staff) cannot be deleted.');
        }

        // Never orphan assigned users — require reassignment first (mirrors
        // the team-member removal guard).
        $userCount = $role->users()->count();
        if ($userCount > 0) {
            return back()->with('error', "Can't delete '{$role->name}' — {$userCount} user".($userCount === 1 ? '' : 's').' still assigned. Reassign first.');
        }

        DB::transaction(function () use ($role, $request): void {
            $this->log($request, 'role.deleted', $role->id, before: ['name' => $role->name]);
            // role_scopes cascades via FK; Spatie clears role_has_permissions
            // and model_has_roles.
            $role->delete();
        });

        return back()->with('success', 'Role deleted.');
    }

    public function togglePermission(int $id, ToggleRolePermissionRequest $request): RedirectResponse
    {
        $role = $this->editableRole($id);
        $data = $request->validated();

        DB::transaction(function () use ($role, $data, $request): void {
            if ($data['granted']) {
                $role->givePermissionTo($data['permission']);
            } else {
                $role->revokePermissionTo($data['permission']);
            }

            $this->log($request, 'role.permission_'.($data['granted'] ? 'granted' : 'revoked'), $role->id, after: [
                'permission' => $data['permission'],
            ]);
        });

        return back()->with('success', 'Permission updated.');
    }

    public function setScope(int $id, SetRoleScopeRequest $request): RedirectResponse
    {
        $role = $this->editableRole($id);
        $data = $request->validated();

        DB::transaction(function () use ($role, $data, $request): void {
            RoleScope::updateOrCreate(
                ['role_id' => $role->id, 'area' => $data['area']],
                ['scope' => $data['scope']],
            );

            $this->log($request, 'role.scope_set', $role->id, after: [
                'area' => $data['area'],
                'scope' => $data['scope'],
            ]);
        });

        return back()->with('success', 'Scope updated.');
    }

    /**
     * Resolve an editable role: must exist on the web guard and must NOT be
     * super_admin (which is never managed here). `is_system_role` is stamped
     * for the rename/delete guards.
     */
    private function editableRole(int $id): Role
    {
        /** @var Role $role */
        $role = Role::where('guard_name', self::GUARD)->findOrFail($id);

        abort_if($role->name === 'super_admin', 403, 'Super Admin is not configurable in this matrix.');

        return $role;
    }

    /**
     * Build the area => scope map for a role, defaulting every area to None
     * where no role_scopes row exists.
     *
     * @param  Collection<int, RoleScope>|null  $rows
     * @return array<string, string>
     */
    private function scopesFor($rows): array
    {
        $map = [];
        foreach (ScopeArea::cases() as $area) {
            $map[$area->value] = AccessScope::None->value;
        }

        foreach ($rows ?? [] as $row) {
            $map[$row->area->value] = $row->scope->value;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(Request $request, string $action, int $roleId, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'role',
            'entity_id' => $roleId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
