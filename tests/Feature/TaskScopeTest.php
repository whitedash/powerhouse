<?php

namespace Tests\Feature;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use App\Models\Customer;
use App\Models\Project;
use App\Models\RoleScope;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Support\ScopeEnforcer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3b-ii: scope enforcement on Tasks, plugged into the shared
 * ScopeEnforcer seam built in 3b-i. Staff is seeded All (the existing suite
 * proves the access-identical path), so these tests cover the NEW behaviour:
 *
 *   - Assigned filters EVERY task surface (MyWork, project board, customer +
 *     lead timelines, dashboard) to the user's own assigned tasks.
 *   - The per-item direct-ID gap: every task action method blocks a task
 *     that isn't the user's — even when the existing OWNERSHIP rule (creator
 *     OR assignee) would have allowed it. That's the strict-assigned rule:
 *     scope (assignee only) gates visibility BEFORE ownership gates mutation.
 *   - None is walled off Tasks entirely (no visibility, no creation).
 *   - super_admin is NOT query-filtered — including eager loads, which
 *     Gate::before never reaches.
 */
class TaskScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * A web role with customers.access (so the user clears the customer-view
     * gate that customer-linked tasks ride) plus a Tasks scope row, and
     * optionally a Projects scope row for the board test.
     */
    private function tasksRole(string $name, AccessScope $tasks, ?AccessScope $projects = null): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->givePermissionTo('customers.access');
        // Step 8: task mutations now require tasks.manage. These tests exercise
        // the Tasks SCOPE layer, so grant tasks.manage to every role — scope
        // denials still 403 (from scope), success paths still pass.
        $role->givePermissionTo('tasks.manage');
        RoleScope::create(['role_id' => $role->id, 'area' => ScopeArea::Tasks->value, 'scope' => $tasks->value]);
        if ($projects !== null) {
            RoleScope::create(['role_id' => $role->id, 'area' => ScopeArea::Projects->value, 'scope' => $projects->value]);
        }

        return $role;
    }

    private function userWith(string $roleName, AccessScope $tasks, ?AccessScope $projects = null): User
    {
        $user = User::factory()->create(); // enum staff → clears EnsureRole
        $user->syncRoles([$this->tasksRole($roleName, $tasks, $projects)->name]);

        return $user->fresh();
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Acme '.uniqid()]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function task(array $attrs): Task
    {
        return Task::create(array_merge([
            'title' => 'T '.uniqid(),
            'type' => 'task',
            'status' => 'todo',
        ], $attrs));
    }

    // ─── Resolution semantics ───

    public function test_effective_scope_resolves_for_tasks(): void
    {
        $this->assertSame(AccessScope::All, ScopeEnforcer::effectiveScope(
            User::factory()->superAdmin()->create()->fresh(), ScopeArea::Tasks,
        ));
        $this->assertSame(AccessScope::All, ScopeEnforcer::effectiveScope(
            User::factory()->create()->fresh(), ScopeArea::Tasks, // seeded staff → All
        ));
        $this->assertSame(AccessScope::Assigned, ScopeEnforcer::effectiveScope(
            $this->userWith('T Assigned', AccessScope::Assigned), ScopeArea::Tasks,
        ));
        $this->assertSame(AccessScope::None, ScopeEnforcer::effectiveScope(
            $this->userWith('T None', AccessScope::None), ScopeArea::Tasks,
        ));
    }

    public function test_multi_role_resolves_to_most_permissive(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([
            $this->tasksRole('TR A', AccessScope::Assigned)->name,
            $this->tasksRole('TR B', AccessScope::All)->name,
        ]);

        $this->assertSame(AccessScope::All, ScopeEnforcer::effectiveScope($user->fresh(), ScopeArea::Tasks));
    }

    // ─── STRICT assigned: created-but-delegated is NOT yours ───

    public function test_assigned_is_strictly_the_assignee_not_the_creator(): void
    {
        $user = $this->userWith('T Strict', AccessScope::Assigned);
        $other = User::factory()->create();

        // Created BY the user but delegated to someone else → invisible.
        $delegated = $this->task(['created_by' => $user->id, 'assigned_to' => $other->id]);
        // Assigned TO the user → visible.
        $mine = $this->task(['created_by' => $other->id, 'assigned_to' => $user->id]);

        $this->assertFalse(ScopeEnforcer::allows($user, ScopeArea::Tasks, $delegated));
        $this->assertTrue(ScopeEnforcer::allows($user, ScopeArea::Tasks, $mine));

        $ids = ScopeEnforcer::applyToList(Task::query(), $user, ScopeArea::Tasks)->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($delegated->id));
    }

    // ─── Per-item direct-ID gap: scope blocks EVEN WHERE ownership would allow ───

    public function test_assigned_blocks_every_action_on_a_created_but_delegated_task(): void
    {
        $user = $this->userWith('T Item', AccessScope::Assigned);
        $other = User::factory()->create();

        // Orphan task the user CREATED but delegated. The existing ownership
        // rule (creator OR assignee) would let the creator edit/pin/reschedule/
        // delete/transition it — so a 403 here proves the SCOPE gate fired.
        $task = $this->task(['created_by' => $user->id, 'assigned_to' => $other->id]);

        $this->actingAs($user)->get("/activities/{$task->id}")->assertForbidden();
        $this->actingAs($user)->put("/tasks/{$task->id}", [])->assertForbidden();
        $this->actingAs($user)->post("/tasks/{$task->id}/complete")->assertForbidden();
        $this->actingAs($user)->post("/tasks/{$task->id}/pin")->assertForbidden();
        $this->actingAs($user)->post("/tasks/{$task->id}/status", ['status' => 'in_progress'])->assertForbidden();
        $this->actingAs($user)->post("/tasks/{$task->id}/reschedule", ['start' => now()->toDateString()])->assertForbidden();
        $this->actingAs($user)->post("/tasks/{$task->id}/quick-complete")->assertForbidden();
        $this->actingAs($user)->post("/tasks/{$task->id}/quick-reschedule", ['due_at' => now()->toDateString()])->assertForbidden();
        $this->actingAs($user)->delete("/tasks/{$task->id}")->assertForbidden();
        // Attachment side-doors ride the same gate (authorizeTaskEdit/View).
        $this->actingAs($user)->post("/tasks/{$task->id}/attachments")->assertForbidden();

        $attachment = TaskAttachment::create([
            'task_id' => $task->id,
            'filename' => 'f.pdf',
            'path' => 'task_attachment/x.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'uploaded_by' => $other->id,
        ]);
        $this->actingAs($user)->get("/tasks/attachments/{$attachment->id}/download")->assertForbidden();

        // The task still in memory is untouched — no mutation slipped through.
        $this->assertSame('todo', $task->fresh()->status);
        $this->assertSame($other->id, $task->fresh()->assigned_to);
    }

    public function test_assigned_allows_the_users_own_task_through_the_scope_gate(): void
    {
        $user = $this->userWith('T Own', AccessScope::Assigned);
        $task = $this->task(['created_by' => $user->id, 'assigned_to' => $user->id]);

        $this->actingAs($user)->get("/activities/{$task->id}")->assertOk();
        $this->actingAs($user)->post("/tasks/{$task->id}/pin")->assertRedirect(); // scope + ownership pass
    }

    // ─── None: no visibility, no creation ───

    public function test_none_blocks_item_access_and_creation(): void
    {
        $user = $this->userWith('T None 2', AccessScope::None);
        $task = $this->task(['created_by' => $user->id, 'assigned_to' => $user->id, 'customer_id' => $this->customer()->id]);

        // Even a task assigned to themselves is invisible under None.
        $this->actingAs($user)->get("/activities/{$task->id}")->assertForbidden();

        // Section gate runs before validation, so empty bodies still 403.
        $this->actingAs($user)->post('/tasks', [])->assertForbidden();
        $this->actingAs($user)->post("/customers/{$task->customer_id}/tasks", [])->assertForbidden();
    }

    public function test_staff_all_scope_can_create_access_identical(): void
    {
        $staff = User::factory()->create(); // All scope

        $this->actingAs($staff)->post('/tasks', [
            'type' => 'task',
            'title' => 'A task',
            'due_at' => now()->addDay()->toDateString(),
        ])->assertRedirect(); // success, no 403

        $this->assertDatabaseHas('tasks', ['title' => 'A task', 'assigned_to' => $staff->id]);
    }

    // ─── Note + reorder side-doors ───

    public function test_assigned_blocks_a_note_onto_a_non_assigned_task(): void
    {
        $user = $this->userWith('T Note', AccessScope::Assigned);
        $other = User::factory()->create();
        $customer = $this->customer();
        $task = $this->task(['customer_id' => $customer->id, 'created_by' => $user->id, 'assigned_to' => $other->id]);

        $this->actingAs($user)->post('/notes', [
            'customer_id' => $customer->id,
            'task_id' => $task->id,
            'body' => 'sneaky',
        ])->assertForbidden();

        $this->assertDatabaseMissing('notes', ['task_id' => $task->id]);
    }

    public function test_assigned_blocks_reordering_a_non_assigned_task(): void
    {
        $user = $this->userWith('T Reorder', AccessScope::Assigned);
        $other = User::factory()->create();
        $mine = $this->task(['assigned_to' => $user->id, 'created_by' => $user->id]);
        $theirs = $this->task(['assigned_to' => $other->id, 'created_by' => $other->id]);

        // A payload that smuggles in a task that isn't theirs → 403.
        $this->actingAs($user)->post('/tasks/reorder', [
            'items' => [
                ['id' => $mine->id, 'sort_order' => 0],
                ['id' => $theirs->id, 'sort_order' => 1],
            ],
        ])->assertForbidden();

        // Their own tasks alone reorder fine.
        $this->actingAs($user)->post('/tasks/reorder', [
            'items' => [['id' => $mine->id, 'sort_order' => 5]],
        ])->assertOk();
    }

    // ─── Listing surfaces: MyWork ───

    public function test_mywork_empties_under_none_and_lists_own_under_assigned(): void
    {
        $none = $this->userWith('MW None', AccessScope::None);
        $this->task(['assigned_to' => $none->id, 'created_by' => $none->id]);
        $this->actingAs($none)->get('/my-work')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('total', 0));

        $assigned = $this->userWith('MW Assigned', AccessScope::Assigned);
        $this->task(['assigned_to' => $assigned->id, 'created_by' => $assigned->id]);
        $this->actingAs($assigned)->get('/my-work')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('total', 1));
    }

    // ─── Listing surfaces: project board eager-load ───

    public function test_project_board_shows_only_own_tasks_under_assigned(): void
    {
        // Projects-Assigned (so they can open a project they're a member of)
        // + Tasks-Assigned (so the board's tasks filter to their own).
        $user = $this->userWith('Board Assigned', AccessScope::Assigned, AccessScope::Assigned);
        $project = Project::create(['title' => 'P '.uniqid(), 'created_by' => User::factory()->create()->id]);
        $project->members()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);

        $other = User::factory()->create();
        $mine = $this->task(['project_id' => $project->id, 'assigned_to' => $user->id, 'created_by' => $other->id]);
        $this->task(['project_id' => $project->id, 'assigned_to' => $other->id, 'created_by' => $other->id]);

        $this->actingAs($user)->get("/projects/{$project->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('project.tasks', 1)
                ->where('project.tasks.0.id', $mine->id));

        // super_admin: eager load is NOT covered by Gate::before, so the
        // explicit All resolution must let them see BOTH tasks.
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->get("/projects/{$project->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('project.tasks', 2));
    }

    public function test_project_files_tab_hides_non_assigned_task_attachments(): void
    {
        // Regression (adversarial review): the Files tab merges task
        // attachments (with task titles) into the project file list. Under
        // Assigned it must show only the member's own task attachments.
        $user = $this->userWith('Files Assigned', AccessScope::Assigned, AccessScope::Assigned);
        $project = Project::create(['title' => 'P '.uniqid(), 'created_by' => User::factory()->create()->id]);
        $project->members()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);

        $other = User::factory()->create();
        $mine = $this->task(['project_id' => $project->id, 'assigned_to' => $user->id, 'created_by' => $other->id]);
        $theirs = $this->task(['project_id' => $project->id, 'assigned_to' => $other->id, 'created_by' => $other->id]);

        foreach ([$mine, $theirs] as $t) {
            TaskAttachment::create([
                'task_id' => $t->id,
                'filename' => 'f.pdf',
                'path' => 'task_attachment/'.uniqid().'.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 10,
                'uploaded_by' => $other->id,
            ]);
        }

        $this->actingAs($user)->get("/projects/{$project->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('files', 1)
                ->where('files.0.task_id', $mine->id));

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->get("/projects/{$project->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('files', 2));
    }

    // ─── Listing surfaces: customer timeline eager-load ───

    public function test_customer_timeline_filters_tasks_under_assigned(): void
    {
        $user = $this->userWith('Cust Assigned', AccessScope::Assigned);
        $customer = $this->customer();
        $other = User::factory()->create();
        $mine = $this->task(['customer_id' => $customer->id, 'assigned_to' => $user->id, 'created_by' => $other->id]);
        $this->task(['customer_id' => $customer->id, 'assigned_to' => $other->id, 'created_by' => $other->id]);

        $this->actingAs($user)->get("/customers/{$customer->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('customer.tasks', 1)
                ->where('customer.tasks.0.id', $mine->id));

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->get("/customers/{$customer->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('customer.tasks', 2));
    }
}
