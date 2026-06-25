<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\RoleScope;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 4 — projects.manage.
 *
 * Project/milestone/time-entry/file mutations now require projects.manage (route
 * middleware), composing with the existing Projects-scope membership +
 * ownership/invoiced/invoices.manage checks. Plus the cross-section IDOR closure
 * in TaskController: the reorder re-bucket and the milestone cascade-completion
 * now require projects.manage + Projects scope on the milestone's project.
 */
class ProjectsManageEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @param array<string,string> $scopes  e.g. ['projects'=>'all','tasks'=>'all'] */
    private function userWith(array $permissions, array $scopes = []): User
    {
        $role = Role::create(['name' => 'proj_'.uniqid(), 'guard_name' => 'web']);
        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }
        foreach ($scopes as $area => $scope) {
            RoleScope::create(['role_id' => $role->id, 'area' => $area, 'scope' => $scope]);
        }
        $user = User::factory()->create();
        $user->syncRoles([$role->name]);

        return $user->fresh();
    }

    private function bareSuperAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create();
        $admin->syncRoles([]);
        $admin->syncPermissions([]);

        return $admin;
    }

    private function makeProject(): Project
    {
        $customer = Customer::create(['name' => 'Acme '.uniqid()]);

        return Project::create([
            'title' => 'Project '.uniqid(),
            'customer_id' => $customer->id,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function makeMilestone(Project $project): Milestone
    {
        return Milestone::create(['project_id' => $project->id, 'title' => 'M '.uniqid(), 'status' => 'pending']);
    }

    private function makeTask(Project $project, ?Milestone $milestone, int $assignedTo, string $status = 'todo'): Task
    {
        return Task::create([
            'type' => 'task',
            'title' => 'Task '.uniqid(),
            'status' => $status,
            'project_id' => $project->id,
            'milestone_id' => $milestone?->id,
            'assigned_to' => $assignedTo,
            'created_by' => $assignedTo,
        ]);
    }

    /** The 13 mutating project/milestone/time-entry/file routes. */
    private function mutationRoutes(): array
    {
        return [
            ['post', '/projects'], ['put', '/projects/1'], ['delete', '/projects/1'],
            ['post', '/projects/1/invoice'], ['post', '/projects/1/files'], ['delete', '/projects/files/1'],
            ['post', '/milestones'], ['post', '/milestones/reorder'], ['put', '/milestones/1'], ['delete', '/milestones/1'],
            ['post', '/time-entries'], ['put', '/time-entries/1'], ['delete', '/time-entries/1'],
        ];
    }

    // ─── route-level projects.manage ───

    public function test_all_project_mutations_403_without_projects_manage(): void
    {
        // customers.access + full Projects/Tasks scope, but NOT projects.manage.
        $user = $this->userWith(['customers.access'], ['projects' => 'all', 'tasks' => 'all']);
        foreach ($this->mutationRoutes() as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }
    }

    public function test_project_destroy_succeeds_with_manage_and_scope(): void
    {
        $user = $this->userWith(['customers.access', 'projects.manage'], ['projects' => 'all']);
        $project = $this->makeProject();

        $this->actingAs($user)->delete("/projects/{$project->id}")->assertRedirect();
        $this->assertNotNull($project->fresh()->archived_at);
    }

    public function test_composition_manage_without_scope_is_blocked(): void
    {
        // projects.manage (passes the route middleware) but Projects scope None
        // → the in-method authorizeScopeItem still 403s. Both required.
        $user = $this->userWith(['customers.access', 'projects.manage']); // no projects scope row → None
        $project = $this->makeProject();

        $this->actingAs($user)->delete("/projects/{$project->id}")->assertForbidden();
        $this->assertNull($project->fresh()->archived_at);
    }

    public function test_superadmin_bypasses_via_gate_before(): void
    {
        $project = $this->makeProject();
        $this->actingAs($this->bareSuperAdmin())->delete("/projects/{$project->id}")->assertRedirect();
        $this->assertNotNull($project->fresh()->archived_at);
    }

    // ─── the cross-section IDOR (the highest-stakes guards) ───

    public function test_idor_reorder_onto_foreign_milestone_is_blocked(): void
    {
        // X: full Tasks scope (so the reorder's tasks-scope check passes) but NO
        // projects.manage and NO Projects scope. Project B is foreign to X.
        $x = $this->userWith(['customers.access'], ['tasks' => 'all']);
        $projectB = $this->makeProject();
        $milestoneB = $this->makeMilestone($projectB);
        // A task X owns, currently on NO milestone.
        $task = $this->makeTask($projectB, null, $x->id);

        $this->actingAs($x)->postJson('/tasks/reorder', [
            'items' => [['id' => $task->id, 'sort_order' => 0, 'milestone_id' => $milestoneB->id]],
        ])->assertForbidden();

        $this->assertNull($task->fresh()->milestone_id); // NOT moved onto B's milestone
    }

    public function test_idor_cascade_complete_foreign_milestone_is_blocked(): void
    {
        // X owns the only task on project B's milestone but is not a B manager.
        $x = $this->userWith(['customers.access'], ['tasks' => 'all']);
        $projectB = $this->makeProject();
        $milestoneB = $this->makeMilestone($projectB);
        $task = $this->makeTask($projectB, $milestoneB, $x->id, 'todo');

        // X completes their own task (allowed via Tasks scope + ownership)...
        $this->actingAs($x)->postJson("/tasks/{$task->id}/status", ['status' => 'complete'])->assertOk();

        // ...the task completes, but B's milestone is NOT cascade-completed.
        $this->assertSame('complete', $task->fresh()->status);
        $this->assertSame('pending', $milestoneB->fresh()->status); // NOT 'completed'
    }

    public function test_project_manager_can_reorder_onto_their_milestone(): void
    {
        // Y manages projects (projects.manage + Projects scope All + Tasks scope)
        // → the legitimate kanban move onto a milestone still works.
        $y = $this->userWith(['customers.access', 'projects.manage'], ['projects' => 'all', 'tasks' => 'all']);
        $project = $this->makeProject();
        $milestone = $this->makeMilestone($project);
        $task = $this->makeTask($project, null, $y->id);

        $this->actingAs($y)->postJson('/tasks/reorder', [
            'items' => [['id' => $task->id, 'sort_order' => 0, 'milestone_id' => $milestone->id]],
        ])->assertOk();

        $this->assertSame($milestone->id, $task->fresh()->milestone_id); // moved successfully
    }

    public function test_idor_store_task_onto_foreign_milestone_is_blocked(): void
    {
        // Create path (store) must close the same re-bucket IDOR as reorder:
        // a Tasks-scoped non-member cannot inject a NEW task onto a foreign
        // project's milestone.
        $x = $this->userWith(['customers.access'], ['tasks' => 'all']);
        $projectB = $this->makeProject();
        $milestoneB = $this->makeMilestone($projectB);

        $this->actingAs($x)->post('/tasks', [
            'type' => 'task', 'title' => 'Injected', 'due_at' => now()->addDay()->toDateString(), 'project_id' => $projectB->id, 'milestone_id' => $milestoneB->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('tasks', ['title' => 'Injected']);
    }

    public function test_idor_store_task_onto_foreign_project_is_blocked(): void
    {
        $x = $this->userWith(['customers.access'], ['tasks' => 'all']);
        $projectB = $this->makeProject();

        $this->actingAs($x)->post('/tasks', [
            'type' => 'task', 'title' => 'Injected board', 'due_at' => now()->addDay()->toDateString(), 'project_id' => $projectB->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('tasks', ['title' => 'Injected board']);
    }

    public function test_manager_can_store_task_onto_their_milestone(): void
    {
        $y = $this->userWith(['customers.access', 'projects.manage'], ['projects' => 'all', 'tasks' => 'all']);
        $project = $this->makeProject();
        $milestone = $this->makeMilestone($project);

        $this->actingAs($y)->post('/tasks', [
            'type' => 'task', 'title' => 'Legit project task', 'due_at' => now()->addDay()->toDateString(), 'project_id' => $project->id, 'milestone_id' => $milestone->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('tasks', ['title' => 'Legit project task', 'milestone_id' => $milestone->id]);
    }

    public function test_crm_task_without_project_is_unaffected(): void
    {
        // No project_id/milestone_id → the projects guard is skipped; a Tasks-
        // scoped user creates a CRM task as before (no over-restriction).
        $user = $this->userWith(['customers.access'], ['tasks' => 'all']);
        $customer = Customer::create(['name' => 'CRM '.uniqid()]);

        $this->actingAs($user)->post('/tasks', [
            'type' => 'task', 'title' => 'CRM task', 'due_at' => now()->addDay()->toDateString(), 'customer_id' => $customer->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('tasks', ['title' => 'CRM task']);
    }
}
