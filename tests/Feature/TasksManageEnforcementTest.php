<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\RoleScope;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 8 — tasks.manage.
 *
 * Task mutations (store/update/destroy/complete/quickComplete/reschedule/
 * quickReschedule/togglePin/updateStatus/reorder/attachments) now require
 * tasks.manage (IN-METHOD — route middleware would over-gate the My Work reads,
 * which are siblings of the mutations), COMPOSING with — never replacing — the
 * Tasks scope + per-item ownership + the step-4 cross-section milestone guards.
 * Cross-section creators CompanyController@storeTask and SupportController@
 * createTask also require tasks.manage. Reads stay scope-only. super_admin
 * bypasses via Gate::before.
 *
 * Composition contract for a mutation — ALL of:
 *   1. Tasks scope (visibility), AND
 *   2. tasks.manage (the NEW capability gate), AND
 *   3. per-item ownership (creator/assignee, where the method enforces it), AND
 *   4. the step-4 projects.manage milestone guard (re-bucket / cascade paths).
 */
class TasksManageEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @param  array<int,string>  $permissions
     * @param  array<string,string>  $scopes
     */
    private function userWith(array $permissions, array $scopes = []): User
    {
        $role = Role::create(['name' => 'tm_'.uniqid(), 'guard_name' => 'web']);
        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }
        foreach ($scopes as $area => $scope) {
            RoleScope::create(['role_id' => $role->id, 'area' => $area, 'scope' => $scope]);
        }
        $user = User::factory()->create(); // role = staff
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

    private function makeTask(int $ownerId, string $status = 'todo'): Task
    {
        return Task::create([
            'type' => 'task',
            'title' => 'T '.uniqid(),
            'status' => $status,
            'assigned_to' => $ownerId,
            'created_by' => $ownerId,
        ]);
    }

    private function makeProject(): Project
    {
        $customer = Company::create(['name' => 'Acme '.uniqid()]);

        return Project::create([
            'title' => 'P '.uniqid(),
            'customer_id' => $customer->id,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    // ─── the NEW gate: without tasks.manage → 403 on every mutation ───

    public function test_all_task_mutations_403_without_tasks_manage(): void
    {
        // Tasks scope All + OWNS the task — only tasks.manage is missing, so the
        // 403 is purely the new capability gate (scope + ownership would pass).
        $user = $this->userWith(['companies.access'], ['tasks' => 'all']);
        $task = $this->makeTask($user->id);

        $routes = [
            ['post', '/tasks'],
            ['put', "/tasks/{$task->id}"],
            ['delete', "/tasks/{$task->id}"],
            ['post', "/tasks/{$task->id}/complete"],
            ['post', "/tasks/{$task->id}/quick-complete"],
            ['post', "/tasks/{$task->id}/reschedule"],
            ['post', "/tasks/{$task->id}/quick-reschedule"],
            ['post', "/tasks/{$task->id}/pin"],
            ['post', "/tasks/{$task->id}/status"],
            ['post', "/tasks/{$task->id}/attachments"],
        ];
        foreach ($routes as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }

        // reorder (bulk; the task is in scope so the scope check passes first)
        $this->actingAs($user)->postJson('/tasks/reorder', [
            'items' => [['id' => $task->id, 'sort_order' => 0]],
        ])->assertForbidden();
    }

    // ─── with tasks.manage + scope + ownership → succeeds ───

    public function test_mutation_succeeds_with_tasks_manage_scope_ownership(): void
    {
        $user = $this->userWith(['companies.access', 'tasks.manage'], ['tasks' => 'all']);
        $task = $this->makeTask($user->id);

        $this->actingAs($user)->post("/tasks/{$task->id}/pin")->assertRedirect();
        $this->assertTrue((bool) $task->fresh()->is_pinned);
    }

    public function test_superadmin_bypasses_via_gate_before(): void
    {
        $task = $this->makeTask(User::factory()->create()->id);
        $this->actingAs($this->bareSuperAdmin())->post("/tasks/{$task->id}/pin")->assertRedirect();
    }

    // ─── COMPOSITION: tasks.manage WITHOUT scope → blocked ───

    public function test_tasks_manage_without_scope_is_blocked(): void
    {
        // tasks.manage but NO Tasks scope row → None → authorizeScopeItem 403s
        // (before the capability gate). Both layers required.
        $user = $this->userWith(['companies.access', 'tasks.manage']); // no tasks scope
        $task = $this->makeTask($user->id);

        $this->actingAs($user)->post("/tasks/{$task->id}/pin")->assertForbidden();
    }

    // ─── COMPOSITION: tasks.manage WITHOUT ownership → blocked ───

    public function test_tasks_manage_without_ownership_is_blocked(): void
    {
        // tasks.manage + All scope, acting on ANOTHER user's task. Scope passes,
        // capability passes, but the per-item ownership check 403s.
        $user = $this->userWith(['companies.access', 'tasks.manage'], ['tasks' => 'all']);
        $foreign = $this->makeTask(User::factory()->create()->id);

        // complete() is assignee-only — a non-assignee with tasks.manage + All is denied.
        $this->actingAs($user)->post("/tasks/{$foreign->id}/complete")->assertForbidden();
        // destroy() is creator-only.
        $this->actingAs($user)->delete("/tasks/{$foreign->id}")->assertForbidden();
    }

    // ─── COMPOSITION: scope + ownership WITHOUT tasks.manage → blocked (the fix) ───

    public function test_scope_and_ownership_without_tasks_manage_is_blocked(): void
    {
        $user = $this->userWith(['companies.access'], ['tasks' => 'all']); // owns task, no manage
        $task = $this->makeTask($user->id);

        $this->actingAs($user)->put("/tasks/{$task->id}", ['title' => 'Edited'])->assertForbidden();
        $this->assertNotSame('Edited', $task->fresh()->title);
    }

    // ─── step-4 IDOR STILL closed under the new gate (tasks.manage must not weaken it) ───

    public function test_step4_idor_reorder_onto_foreign_milestone_still_blocked(): void
    {
        // HAS tasks.manage + Tasks All, but NOT projects.manage / Projects scope.
        // tasks.manage passes; the step-4 canManageMilestoneProject guard still 403s.
        $user = $this->userWith(['companies.access', 'tasks.manage'], ['tasks' => 'all']);
        $projectB = $this->makeProject();
        $milestoneB = Milestone::create(['project_id' => $projectB->id, 'title' => 'M', 'status' => 'pending']);
        $task = $this->makeTask($user->id);

        $this->actingAs($user)->postJson('/tasks/reorder', [
            'items' => [['id' => $task->id, 'sort_order' => 0, 'milestone_id' => $milestoneB->id]],
        ])->assertForbidden();
        $this->assertNull($task->fresh()->milestone_id); // NOT moved onto B's milestone
    }

    public function test_step4_idor_store_onto_foreign_milestone_still_blocked(): void
    {
        $user = $this->userWith(['companies.access', 'tasks.manage'], ['tasks' => 'all']);
        $projectB = $this->makeProject();
        $milestoneB = Milestone::create(['project_id' => $projectB->id, 'title' => 'M', 'status' => 'pending']);

        $this->actingAs($user)->post('/tasks', [
            'type' => 'task', 'title' => 'Injected', 'due_at' => now()->addDay()->toDateString(),
            'project_id' => $projectB->id, 'milestone_id' => $milestoneB->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('tasks', ['title' => 'Injected']);
    }

    // ─── cross-section creators also require tasks.manage ───

    public function test_customer_store_task_requires_tasks_manage(): void
    {
        // companies.manage (satisfies the customer update gate) + Tasks scope, but
        // NOT tasks.manage → CompanyController@storeTask 403s.
        $user = $this->userWith(['companies.access', 'companies.manage'], ['tasks' => 'all']);
        $customer = Company::create(['name' => 'Cust '.uniqid()]);

        $this->actingAs($user)->post("/companies/{$customer->id}/tasks", [
            'title' => 'CRM task', 'due_at' => now()->addDay()->toDateString(),
        ])->assertForbidden();
        $this->assertDatabaseMissing('tasks', ['title' => 'CRM task']);
    }

    public function test_support_create_task_requires_tasks_manage_even_with_support_manage(): void
    {
        // Holds support.manage (passes the step-7 route gate) + Support & Tasks
        // scope, but NOT tasks.manage → the support-originated task is blocked.
        $user = $this->userWith(['companies.access', 'support.manage'], ['support' => 'all', 'tasks' => 'all']);
        $ticket = SupportTicket::create([
            'customer_id' => Company::create(['name' => 'T '.uniqid()])->id,
            'subject' => 'S', 'status' => 'open', 'priority' => 'medium',
        ]);

        $this->actingAs($user)->post("/helpdesk/{$ticket->id}/task", [
            'title' => 'From support', 'type' => 'task', 'priority' => 'medium',
            'due_at' => now()->addDay()->toDateString(),
        ])->assertForbidden();
        $this->assertDatabaseMissing('tasks', ['title' => 'From support']);
    }
}
