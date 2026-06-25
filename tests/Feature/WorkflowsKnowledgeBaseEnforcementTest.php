<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 9 — workflows (access+manage) +
 * knowledge_base (access+manage). The LAST enforcement section. Both controllers
 * had ZERO authz (coarse role only); now reads gate the .access permission and
 * mutations gate the .manage permission (clean route middleware, no composition).
 *
 * workflows.manage is the automation control point: WorkflowEngine executes on
 * events (system authority), so gating who can BUILD/EDIT/toggle a workflow is
 * what controls the per-execution automation actions left ungated in steps 5/7/8.
 *
 * super_admin bypasses via Gate::before. The PUBLIC /kb viewer
 * (Public\KnowledgeBaseController) stays ungated.
 */
class WorkflowsKnowledgeBaseEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @param array<int,string> $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'wf_'.uniqid(), 'guard_name' => 'web']);
        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
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

    private function makeWorkflow(): Workflow
    {
        return Workflow::create([
            'name' => 'WF '.uniqid(),
            'trigger_type' => 'manual',
            'trigger_config' => [],
            'is_active' => true,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    // ─── workflows.access (reads) ───

    public function test_workflows_index_403_without_workflows_access(): void
    {
        $this->actingAs($this->userWith([]))->get('/workflows')->assertForbidden();
    }

    public function test_workflows_index_succeeds_with_workflows_access(): void
    {
        $this->actingAs($this->userWith(['workflows.access']))->get('/workflows')->assertOk();
    }

    // ─── workflows.manage (mutations) ───

    public function test_workflow_mutations_403_without_workflows_manage(): void
    {
        // Has read access but NOT manage — every mutation is blocked.
        $user = $this->userWith(['workflows.access']);
        foreach ([['post', '/workflows'], ['put', '/workflows/1'], ['delete', '/workflows/1'], ['post', '/workflows/1/toggle']] as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }
    }

    public function test_workflow_toggle_succeeds_with_workflows_manage(): void
    {
        $user = $this->userWith(['workflows.access', 'workflows.manage']);
        $workflow = $this->makeWorkflow();

        $this->actingAs($user)->post("/workflows/{$workflow->id}/toggle")->assertOk(); // toggle returns JSON
        $this->assertFalse((bool) $workflow->fresh()->is_active); // flipped from true
    }

    public function test_workflow_superadmin_bypasses(): void
    {
        $admin = $this->bareSuperAdmin();
        $workflow = $this->makeWorkflow();
        $this->actingAs($admin)->get('/workflows')->assertOk();
        $this->actingAs($admin)->post("/workflows/{$workflow->id}/toggle")->assertOk();
    }

    // ─── knowledge_base.access (reads) — NB: routes are /help, perm is knowledge_base.* ───

    public function test_kb_reads_403_without_knowledge_base_access(): void
    {
        $user = $this->userWith([]);
        $this->actingAs($user)->get('/help')->assertForbidden();
        $this->actingAs($user)->get('/help/some-slug')->assertForbidden();
    }

    public function test_kb_index_succeeds_with_knowledge_base_access(): void
    {
        $this->actingAs($this->userWith(['knowledge_base.access']))->get('/help')->assertOk();
    }

    // ─── knowledge_base.manage (mutations) ───

    public function test_kb_mutations_403_without_knowledge_base_manage(): void
    {
        $user = $this->userWith(['knowledge_base.access']); // read but not manage
        foreach ([['post', '/help'], ['put', '/help/1'], ['delete', '/help/1']] as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }
    }

    public function test_kb_store_succeeds_with_knowledge_base_manage(): void
    {
        $user = $this->userWith(['knowledge_base.access', 'knowledge_base.manage']);

        $this->actingAs($user)->post('/help', [
            'title' => 'New Article', 'content' => 'Body text', 'category' => 'General',
        ])->assertRedirect();
        $this->assertDatabaseHas('support_knowledge_base', ['title' => 'New Article']);
    }

    public function test_kb_superadmin_bypasses(): void
    {
        $this->actingAs($this->bareSuperAdmin())->get('/help')->assertOk();
    }

    // ─── the public /kb viewer stays ungated (no permission required) ───

    public function test_public_kb_viewer_is_not_gated(): void
    {
        // A bare staff user with NO knowledge_base.* perms can still hit the
        // public viewer — it must not require the staff KB permission.
        $this->actingAs($this->userWith([]))->get('/kb')->assertOk();
    }
}
