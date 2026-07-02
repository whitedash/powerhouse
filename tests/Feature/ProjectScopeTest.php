<?php

namespace Tests\Feature;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\RoleScope;
use App\Models\Task;
use App\Models\User;
use App\Support\ScopeEnforcer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3b-i: scope enforcement on Projects (the first scoped area) + the
 * shared ScopeEnforcer mechanism. Staff is seeded All scope, so the existing
 * suite proves the All path is access-identical; these tests cover the NEW
 * Assigned/None behaviour, super_admin (NOT query-filtered), multi-role
 * resolution, and the milestone/time-entry sub-resource side-doors.
 */
class ProjectScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function projectRole(string $name, AccessScope $scope): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        // companies.access lets them past the index/show viewAny(Company)
        // gate, so the test isolates the SCOPE behaviour (not that gate).
        $role->givePermissionTo('companies.access');
        RoleScope::create(['role_id' => $role->id, 'area' => ScopeArea::Projects->value, 'scope' => $scope->value]);

        return $role;
    }

    private function makeProject(): Project
    {
        return Project::create(['title' => 'P '.uniqid(), 'created_by' => User::factory()->create()->id]);
    }

    // ─── Access-identical baseline: staff = All ───

    public function test_staff_all_scope_sees_every_project_including_non_member(): void
    {
        $staff = User::factory()->create(); // staff role → All scope (seeded)
        $a = $this->makeProject();
        $b = $this->makeProject();

        $this->actingAs($staff)->get('/projects')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Internal/Projects/Index')->has('projects.data', 2));

        // Not a member of either, but All scope → reachable.
        $this->actingAs($staff)->get("/projects/{$a->id}")->assertOk();
    }

    // ─── Assigned: list filtered + per-item blocked ───

    public function test_assigned_scope_lists_only_member_projects(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([$this->projectRole('PM Assigned', AccessScope::Assigned)->name]);

        $mine = $this->makeProject();
        $mine->members()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);
        $other = $this->makeProject();

        $this->actingAs($user)->get('/projects')->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('projects.data', 1)
                ->where('projects.data.0.id', $mine->id));
    }

    public function test_assigned_scope_blocks_non_member_project_by_direct_id(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([$this->projectRole('PM Assigned 2', AccessScope::Assigned)->name]);

        $mine = $this->makeProject();
        $mine->members()->attach($user->id, ['role' => 'viewer', 'joined_at' => now()]);
        $other = $this->makeProject();

        $this->actingAs($user)->get("/projects/{$mine->id}")->assertOk();         // member (any role)
        $this->actingAs($user)->get("/projects/{$other->id}")->assertForbidden();  // non-member → 403
        $this->actingAs($user)->put("/projects/{$other->id}", [])->assertForbidden();
        $this->actingAs($user)->delete("/projects/{$other->id}")->assertForbidden();
    }

    // ─── None: no section access ───

    public function test_none_scope_blocks_section_and_items(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([$this->projectRole('No Projects', AccessScope::None)->name]);

        $project = $this->makeProject();

        $this->actingAs($user)->get('/projects')->assertForbidden();
        $this->actingAs($user)->get("/projects/{$project->id}")->assertForbidden();
    }

    // ─── super_admin: NOT query-filtered (the Gate::before-doesn't-cover-queries guard) ───

    public function test_super_admin_is_not_query_filtered_and_sees_all(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $a = $this->makeProject();
        $b = $this->makeProject();

        $this->actingAs($admin)->get('/projects')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('projects.data', 2)); // both, despite membership of neither

        $this->actingAs($admin)->get("/projects/{$a->id}")->assertOk();

        $this->assertSame(AccessScope::All, ScopeEnforcer::effectiveScope($admin->fresh(), ScopeArea::Projects));
    }

    // ─── Multi-role: most-permissive wins ───

    public function test_multi_role_resolves_to_most_permissive(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([
            $this->projectRole('PM A', AccessScope::Assigned)->name,
            $this->projectRole('PM B', AccessScope::All)->name,
        ]);

        $this->assertSame(AccessScope::All, ScopeEnforcer::effectiveScope($user->fresh(), ScopeArea::Projects));

        // All wins → can open a project they're not a member of.
        $other = $this->makeProject();
        $this->actingAs($user)->get("/projects/{$other->id}")->assertOk();
    }

    public function test_resolver_defaults_to_none_without_a_scope_row(): void
    {
        $user = User::factory()->create();
        // Role with a permission but NO projects scope row → defaults None.
        $role = Role::create(['name' => 'Scopeless', 'guard_name' => 'web']);
        $role->givePermissionTo('companies.access');
        $user->syncRoles(['Scopeless']);

        $this->assertSame(AccessScope::None, ScopeEnforcer::effectiveScope($user->fresh(), ScopeArea::Projects));
    }

    // ─── Sub-resource side-doors: milestones + time-entries of a non-member project ───

    public function test_assigned_scope_blocks_subresources_of_non_member_project(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([$this->projectRole('PM Sub', AccessScope::Assigned)->name]);

        $other = $this->makeProject(); // user is NOT a member

        // Milestone create on a non-member project → 403.
        $this->actingAs($user)->post('/milestones', ['project_id' => $other->id, 'title' => 'M'])
            ->assertForbidden();

        // Milestone update on a non-member project's milestone → 403.
        $milestone = Milestone::create(['project_id' => $other->id, 'title' => 'M']);
        $this->actingAs($user)->put("/milestones/{$milestone->id}", ['title' => 'X', 'status' => 'pending'])
            ->assertForbidden();

        // Time logged against a non-member project's task → 403.
        $task = Task::create([
            'project_id' => $other->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'title' => 'T',
        ]);
        $this->actingAs($user)->post('/time-entries', [
            'task_id' => $task->id,
            'minutes' => 30,
            'logged_at' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_staff_all_scope_can_use_subresources_access_identical(): void
    {
        // Guards that the new sub-resource gates are no-ops for All scope.
        $staff = User::factory()->create();
        $project = $this->makeProject();

        $this->actingAs($staff)->post('/milestones', ['project_id' => $project->id, 'title' => 'M'])
            ->assertRedirect(); // success (no 403)
    }
}
