<?php

namespace Tests\Feature;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use App\Models\Customer;
use App\Models\RoleScope;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 7 — support.manage.
 *
 * The 4 ticket mutations (store/reply/updateStatus/createTask) now require
 * permission:support.manage (route middleware), COMPOSING with — never
 * replacing — the existing in-method Support scope + support.view_unassigned
 * predicate. Reads (index/show) stay scope-only. createTask additionally keeps
 * its in-method Tasks-section scope gate.
 *
 * The composition contract a mutation must satisfy ALL of:
 *   1. support.manage (the NEW section gate), AND
 *   2. scope-to-ticket (section != None AND the per-item predicate), AND
 *   3. for an unassigned ticket under Assigned scope: support.view_unassigned.
 * super_admin bypasses via Gate::before.
 */
class SupportEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWith(string $name, AccessScope $support, bool $viewUnassigned = false, ?AccessScope $tasks = null, bool $manage = false): User
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        if ($viewUnassigned) {
            $role->givePermissionTo('support.view_unassigned');
        }
        if ($manage) {
            $role->givePermissionTo('support.manage');
        }
        RoleScope::create(['role_id' => $role->id, 'area' => ScopeArea::Support->value, 'scope' => $support->value]);
        if ($tasks !== null) {
            RoleScope::create(['role_id' => $role->id, 'area' => ScopeArea::Tasks->value, 'scope' => $tasks->value]);
            // Combined Priority-2 tree: SupportController@createTask now also
            // requires tasks.manage (step 8) on top of support.manage (step 7).
            // A user given Tasks scope here also gets tasks.manage so the
            // createTask success path works; the support.manage 403 tests are
            // unaffected (their route gate fires first).
            $role->givePermissionTo('tasks.manage');
        }
        $user = User::factory()->create(); // enum staff → clears EnsureRole
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

    private function customer(): Customer
    {
        return Customer::create(['name' => 'C '.uniqid()]);
    }

    /** @param array<string,mixed> $attrs */
    private function ticket(array $attrs = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'customer_id' => $this->customer()->id,
            'subject' => 'T '.uniqid(),
            'status' => 'open',
            'priority' => 'medium',
        ], $attrs));
    }

    private function storePayload(int $customerId): array
    {
        return ['customer_id' => $customerId, 'subject' => 'Subj', 'message' => 'Body', 'priority' => 'medium'];
    }

    private function taskPayload(): array
    {
        return ['title' => 'Follow up', 'type' => 'task', 'priority' => 'medium', 'due_at' => now()->addDay()->toDateString()];
    }

    // ─── the NEW gate: without support.manage → 403 on all 4 mutations ───

    public function test_all_four_mutations_403_without_support_manage(): void
    {
        // Full Support scope + view_unassigned + Tasks scope — everything EXCEPT
        // support.manage. Previously this user could mutate; now blocked.
        $user = $this->userWith('NoManage', AccessScope::All, viewUnassigned: true, tasks: AccessScope::All);
        foreach ([['post', '/helpdesk'], ['post', '/helpdesk/1/reply'], ['post', '/helpdesk/1/status'], ['post', '/helpdesk/1/task']] as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }
    }

    // ─── with support.manage + scope → succeeds ───

    public function test_mutations_succeed_with_manage_and_scope(): void
    {
        $user = $this->userWith('FullMgr', AccessScope::All, tasks: AccessScope::All, manage: true);
        $customer = $this->customer();

        $this->actingAs($user)->post('/helpdesk', $this->storePayload($customer->id))->assertRedirect();
        $ticket = SupportTicket::where('customer_id', $customer->id)->firstOrFail();
        $this->actingAs($user)->post("/helpdesk/{$ticket->id}/reply", ['message' => 'Reply body'])->assertRedirect();
        $this->actingAs($user)->post("/helpdesk/{$ticket->id}/status", ['status' => 'in_progress'])->assertRedirect();
        $this->actingAs($user)->post("/helpdesk/{$ticket->id}/task", $this->taskPayload())->assertRedirect();
        // createTask links the spun-off task to the ticket's CUSTOMER (+ an
        // internal note), not via tasks.ticket_id — assert on what it sets.
        $this->assertDatabaseHas('tasks', ['title' => 'Follow up', 'customer_id' => $ticket->customer_id]);
    }

    public function test_superadmin_bypasses_via_gate_before(): void
    {
        $admin = $this->bareSuperAdmin();
        $customer = $this->customer();
        $this->actingAs($admin)->post('/helpdesk', $this->storePayload($customer->id))->assertRedirect();
        $ticket = SupportTicket::where('customer_id', $customer->id)->firstOrFail();
        $this->actingAs($admin)->post("/helpdesk/{$ticket->id}/reply", ['message' => 'Admin reply'])->assertRedirect();
    }

    // ─── COMPOSITION: manage WITHOUT scope-to-ticket → still blocked ───

    public function test_manage_without_scope_to_ticket_is_blocked(): void
    {
        // support.manage + Assigned scope, acting on ANOTHER agent's ticket.
        // Route gate passes; the in-method scope item predicate 403s.
        $user = $this->userWith('MgrAssigned', AccessScope::Assigned, manage: true);
        $theirs = $this->ticket(['assigned_to' => User::factory()->create()->id]);

        $this->actingAs($user)->post("/helpdesk/{$theirs->id}/reply", ['message' => 'x'])->assertForbidden();
        $this->actingAs($user)->post("/helpdesk/{$theirs->id}/status", ['status' => 'in_progress'])->assertForbidden();
    }

    public function test_manage_with_none_scope_cannot_create(): void
    {
        // support.manage but Support=None → store's authorizeScopeSection(None) 403s.
        $user = $this->userWith('MgrNone', AccessScope::None, manage: true);
        $this->actingAs($user)->post('/helpdesk', $this->storePayload($this->customer()->id))->assertForbidden();
    }

    // ─── COMPOSITION: scope-to-ticket WITHOUT manage → now blocked (the fix) ───

    public function test_scope_to_ticket_without_manage_is_blocked(): void
    {
        // Owns the ticket (Assigned scope) but lacks support.manage → 403.
        $user = $this->userWith('OwnerNoManage', AccessScope::Assigned);
        $mine = $this->ticket(['assigned_to' => $user->id]);

        $this->actingAs($user)->post("/helpdesk/{$mine->id}/reply", ['message' => 'x'])->assertForbidden();
        $this->actingAs($user)->post("/helpdesk/{$mine->id}/status", ['status' => 'in_progress'])->assertForbidden();
    }

    // ─── COMPOSITION: view_unassigned still governs the unassigned pool ───

    public function test_manage_plus_view_unassigned_can_act_on_unassigned(): void
    {
        $user = $this->userWith('SelfServeMgr', AccessScope::Assigned, viewUnassigned: true, manage: true);
        $unassigned = $this->ticket(['assigned_to' => null]);

        $this->actingAs($user)->post("/helpdesk/{$unassigned->id}/reply", ['message' => 'On it'])->assertRedirect();
    }

    public function test_manage_without_view_unassigned_cannot_act_on_unassigned(): void
    {
        // support.manage + Assigned but NO view_unassigned → the pool stays closed.
        $user = $this->userWith('MgrNoPool', AccessScope::Assigned, viewUnassigned: false, manage: true);
        $unassigned = $this->ticket(['assigned_to' => null]);

        $this->actingAs($user)->post("/helpdesk/{$unassigned->id}/reply", ['message' => 'x'])->assertForbidden();
        $this->actingAs($user)->post("/helpdesk/{$unassigned->id}/status", ['status' => 'in_progress'])->assertForbidden();
    }

    // ─── createTask keeps its Tasks-section gate (orthogonal to support.manage) ───

    public function test_create_task_still_requires_tasks_scope(): void
    {
        // support.manage + Support=All (can see the ticket) but Tasks=None →
        // the in-method authorizeScopeSection(Tasks) still 403s.
        $user = $this->userWith('MgrNoTasks', AccessScope::All, manage: true); // no tasks scope row → None
        $ticket = $this->ticket(['assigned_to' => null]);

        $this->actingAs($user)->post("/helpdesk/{$ticket->id}/task", $this->taskPayload())->assertForbidden();
        $this->assertDatabaseMissing('tasks', ['ticket_id' => $ticket->id]);
    }
}
