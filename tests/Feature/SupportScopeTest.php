<?php

namespace Tests\Feature;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use App\Models\Customer;
use App\Models\RoleScope;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Support\ScopeEnforcer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3b-iv: scope enforcement on Support — the only area whose Assigned
 * filter COMPOSES with a separate permission (support.view_unassigned). Staff
 * is seeded All + view_unassigned, so the existing suite proves the unchanged
 * path; these cover every composition cell:
 *
 *   All                       → every ticket (view_unassigned moot)
 *   Assigned, no view_unassigned → ONLY own (NULL/unassigned NOT visible)
 *   Assigned, WITH view_unassigned → own + unassigned pool, NEVER another agent's
 *   None                      → no access
 *
 * …across the list, the per-item gate on every action method, the reply-to-
 * unassigned flow, search, and the Task→ticket cross-reference.
 */
class SupportScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // $manage grants support.manage (step 7): the section-wide gate the 4 ticket
    // MUTATIONS now require via route middleware. Mutation tests pass manage:true
    // so the SCOPE composition (assigned / view_unassigned) stays the layer under
    // test rather than being masked by the manage gate 403ing first.
    private function supportRole(string $name, AccessScope $support, bool $viewUnassigned = false, ?AccessScope $tasks = null, bool $manage = false): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        // Step 8: SupportController@createTask now ALSO requires tasks.manage
        // (a support-originated CRM task is a tasks-section action). Granted here
        // so the support-scope test that spins a task off a ticket stays green;
        // the other support tests don't create tasks and are unaffected.
        $role->givePermissionTo('tasks.manage');
        if ($viewUnassigned) {
            $role->givePermissionTo('support.view_unassigned');
        }
        if ($manage) {
            $role->givePermissionTo('support.manage');
        }
        RoleScope::create(['role_id' => $role->id, 'area' => ScopeArea::Support->value, 'scope' => $support->value]);
        if ($tasks !== null) {
            RoleScope::create(['role_id' => $role->id, 'area' => ScopeArea::Tasks->value, 'scope' => $tasks->value]);
        }

        return $role;
    }

    private function userWith(string $name, AccessScope $support, bool $viewUnassigned = false, ?AccessScope $tasks = null, bool $manage = false): User
    {
        $user = User::factory()->create(); // enum staff → clears EnsureRole
        $user->syncRoles([$this->supportRole($name, $support, $viewUnassigned, $tasks, $manage)->name]);

        return $user->fresh();
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'C '.uniqid()]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function ticket(array $attrs): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'customer_id' => $this->customer()->id,
            'subject' => 'T '.uniqid(),
            'status' => 'open',
            'priority' => 'medium',
        ], $attrs));
    }

    // ─── Resolution semantics ───

    public function test_effective_scope_resolves_for_support(): void
    {
        $this->assertSame(AccessScope::All, ScopeEnforcer::effectiveScope(
            User::factory()->superAdmin()->create()->fresh(), ScopeArea::Support));
        $this->assertSame(AccessScope::All, ScopeEnforcer::effectiveScope(
            User::factory()->create()->fresh(), ScopeArea::Support)); // seeded staff → All
        $this->assertSame(AccessScope::Assigned, ScopeEnforcer::effectiveScope(
            $this->userWith('Sup A', AccessScope::Assigned), ScopeArea::Support));
        $this->assertSame(AccessScope::None, ScopeEnforcer::effectiveScope(
            $this->userWith('Sup N', AccessScope::None), ScopeArea::Support));
    }

    // ─── Composition cell: Assigned WITHOUT view_unassigned → only own ───

    public function test_assigned_without_view_unassigned_sees_only_own(): void
    {
        $user = $this->userWith('Sup OwnOnly', AccessScope::Assigned, viewUnassigned: false);
        $other = User::factory()->create();
        $mine = $this->ticket(['assigned_to' => $user->id]);
        $theirs = $this->ticket(['assigned_to' => $other->id]);
        $unassigned = $this->ticket(['assigned_to' => null]);

        // allows()
        $this->assertTrue(ScopeEnforcer::allows($user, ScopeArea::Support, $mine));
        $this->assertFalse(ScopeEnforcer::allows($user, ScopeArea::Support, $theirs));
        $this->assertFalse(ScopeEnforcer::allows($user, ScopeArea::Support, $unassigned)); // no perm → NULL invisible

        // list
        $this->actingAs($user)->get('/helpdesk')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('tickets.data', 1)->where('tickets.data.0.id', $mine->id));

        // per-item, direct ID
        $this->actingAs($user)->get("/helpdesk/{$mine->id}")->assertOk();
        $this->actingAs($user)->get("/helpdesk/{$theirs->id}")->assertForbidden();
        $this->actingAs($user)->get("/helpdesk/{$unassigned->id}")->assertForbidden();
    }

    // ─── Composition cell: Assigned WITH view_unassigned → own + unassigned ───

    public function test_assigned_with_view_unassigned_sees_own_plus_unassigned_not_others(): void
    {
        $user = $this->userWith('Sup SelfServe', AccessScope::Assigned, viewUnassigned: true);
        $other = User::factory()->create();
        $mine = $this->ticket(['assigned_to' => $user->id]);
        $theirs = $this->ticket(['assigned_to' => $other->id]);
        $unassigned = $this->ticket(['assigned_to' => null]);

        $this->assertTrue(ScopeEnforcer::allows($user, ScopeArea::Support, $mine));
        $this->assertTrue(ScopeEnforcer::allows($user, ScopeArea::Support, $unassigned)); // perm → pool visible
        $this->assertFalse(ScopeEnforcer::allows($user, ScopeArea::Support, $theirs));    // never another agent's

        $this->actingAs($user)->get('/helpdesk')->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('tickets.data', 2)
                ->where('tickets.data', fn ($d) => collect($d)->pluck('id')->sort()->values()->all()
                    === collect([$mine->id, $unassigned->id])->sort()->values()->all()));

        $this->actingAs($user)->get("/helpdesk/{$mine->id}")->assertOk();
        $this->actingAs($user)->get("/helpdesk/{$unassigned->id}")->assertOk();   // pool
        $this->actingAs($user)->get("/helpdesk/{$theirs->id}")->assertForbidden(); // other agent
    }

    // ─── All + super_admin → everything ───

    public function test_all_and_super_admin_see_every_ticket(): void
    {
        $other = User::factory()->create();
        $a = $this->ticket(['assigned_to' => $other->id]);
        $b = $this->ticket(['assigned_to' => null]);

        $staff = User::factory()->create(); // All + view_unassigned (seeded)
        $this->actingAs($staff)->get('/helpdesk')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('tickets.data', 2));
        $this->actingAs($staff)->get("/helpdesk/{$a->id}")->assertOk();

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->get('/helpdesk')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('tickets.data', 2));
        $this->actingAs($admin)->get("/helpdesk/{$a->id}")->assertOk();
        $this->assertSame(AccessScope::All, ScopeEnforcer::effectiveScope($admin->fresh(), ScopeArea::Support));
    }

    // ─── None → no access, no creation ───

    public function test_none_blocks_section_item_and_creation(): void
    {
        // manage:true so the create 403 proves the None SCOPE blocks (not the
        // manage gate). Reads are scope-only (manage doesn't gate them).
        $user = $this->userWith('Sup None 2', AccessScope::None, manage: true);
        $mine = $this->ticket(['assigned_to' => $user->id]);

        $this->actingAs($user)->get('/helpdesk')->assertForbidden();              // section gate
        $this->actingAs($user)->get("/helpdesk/{$mine->id}")->assertForbidden();  // item gate
        $this->actingAs($user)->post('/helpdesk', [])->assertForbidden();         // creation gate (pre-validation)
    }

    // ─── Per-item gate on every ACTION method (reply / updateStatus / createTask) ───

    public function test_action_methods_enforce_the_composition(): void
    {
        // Self-serve + Tasks=All so the Tasks section gate on createTask passes
        // and the SUPPORT gate is what's exercised.
        $user = $this->userWith('Sup Actions', AccessScope::Assigned, viewUnassigned: true, tasks: AccessScope::All, manage: true);
        $other = User::factory()->create();
        $theirs = $this->ticket(['assigned_to' => $other->id]);
        $unassigned = $this->ticket(['assigned_to' => null]);

        // Another agent's ticket → 403 on every action (gate fires pre-validation).
        $this->actingAs($user)->post("/helpdesk/{$theirs->id}/reply", [])->assertForbidden();
        $this->actingAs($user)->post("/helpdesk/{$theirs->id}/status", [])->assertForbidden();
        $this->actingAs($user)->post("/helpdesk/{$theirs->id}/task", [])->assertForbidden();

        // Unassigned ticket the self-serve agent may see → actions are permitted
        // (not 403). createTask spins a task off it.
        $this->actingAs($user)->post("/helpdesk/{$unassigned->id}/task", [
            'title' => 'Follow up', 'type' => 'task', 'priority' => 'medium',
            'due_at' => now()->addDay()->toDateString(),
        ])->assertRedirect();
    }

    public function test_action_methods_deny_unassigned_without_view_unassigned(): void
    {
        // Plain Assigned agent (no view_unassigned) cannot act on the pool.
        $user = $this->userWith('Sup NoPool', AccessScope::Assigned, viewUnassigned: false, manage: true);
        $unassigned = $this->ticket(['assigned_to' => null]);

        $this->actingAs($user)->post("/helpdesk/{$unassigned->id}/reply", [])->assertForbidden();
        $this->actingAs($user)->post("/helpdesk/{$unassigned->id}/status", [])->assertForbidden();
    }

    // ─── Auto-assign / grab flow (point 6) ───

    public function test_self_serve_agent_can_reply_to_unassigned_then_claim_it(): void
    {
        $user = $this->userWith('Sup Grab', AccessScope::Assigned, viewUnassigned: true, manage: true);
        $unassigned = $this->ticket(['assigned_to' => null]);

        // Gate permits replying to the pool ticket; reply does NOT auto-assign,
        // so it stays unassigned (still visible via view_unassigned).
        $this->actingAs($user)->post("/helpdesk/{$unassigned->id}/reply", ['message' => 'On it.'])
            ->assertRedirect();
        $this->assertNull($unassigned->fresh()->assigned_to);

        // Claiming happens via updateStatus — set assigned_to = self. After this
        // the ticket is theirs under Assigned.
        $this->actingAs($user)->post("/helpdesk/{$unassigned->id}/status", [
            'status' => 'in_progress', 'assigned_to' => $user->id,
        ])->assertRedirect();
        $this->assertSame($user->id, $unassigned->fresh()->assigned_to);
        $this->assertTrue(ScopeEnforcer::allows($user->fresh(), ScopeArea::Support, $unassigned->fresh()));
    }

    // ─── Cross-surface: nav badges (adversarial review) ───

    public function test_nav_support_badges_follow_the_composed_scope(): void
    {
        $other = User::factory()->create();
        $ownerNoPool = $this->userWith('Nav OwnerNoPool', AccessScope::Assigned, viewUnassigned: false);
        $selfServe = $this->userWith('Nav SelfServe', AccessScope::Assigned, viewUnassigned: true);

        $this->ticket(['assigned_to' => $ownerNoPool->id]); // A — owner's
        $this->ticket(['assigned_to' => $selfServe->id]);   // B — self-serve's
        $this->ticket(['assigned_to' => $other->id]);       // C — another agent's
        $this->ticket(['assigned_to' => null]);             // D — unassigned pool

        // Assigned, no view_unassigned → own only (A) = 1.
        $this->actingAs($ownerNoPool)->get('/helpdesk')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('nav.support_open', 1));

        // Self-serve → own (B) + unassigned (D) = 2, NOT another agent's.
        $this->actingAs($selfServe)->get('/helpdesk')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('nav.support_open', 2));

        // All staff → every open ticket = 4 (the cached platform count).
        $this->actingAs(User::factory()->create())->get('/helpdesk')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('nav.support_open', 4));
    }

    // ─── Cross-surface: global search ───

    public function test_search_only_surfaces_in_scope_tickets(): void
    {
        $user = $this->userWith('Sup Search', AccessScope::Assigned, viewUnassigned: false);
        $other = User::factory()->create();
        $mine = $this->ticket(['assigned_to' => $user->id, 'subject' => 'ZZUNIQUE mine ticket']);
        $this->ticket(['assigned_to' => $other->id, 'subject' => 'ZZUNIQUE their ticket']);

        $res = $this->actingAs($user)->getJson('/search?q=ZZUNIQUE');
        $res->assertOk();
        $ticketTitles = collect($res->json('results'))->where('type', 'ticket')->pluck('title')->all();
        $this->assertSame(['ZZUNIQUE mine ticket'], $ticketTitles);
    }

    // ─── Cross-surface: Task→ticket linkage (reverse cross-reference) ───

    public function test_task_ticket_linkage_hides_out_of_scope_ticket_subject(): void
    {
        // User can see their TASK (Tasks Assigned) but not another agent's TICKET.
        $user = $this->userWith('Sup TaskLink', AccessScope::Assigned, viewUnassigned: false, tasks: AccessScope::Assigned);
        $other = User::factory()->create();

        $foreignTicket = $this->ticket(['assigned_to' => $other->id]);
        $task = Task::create([
            'title' => 'Triage', 'type' => 'task', 'status' => 'todo',
            'ticket_id' => $foreignTicket->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
        ]);

        // Task is visible (it's theirs), but the ticket cross-reference is hidden.
        $this->actingAs($user)->get("/activities/{$task->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('task.ticket', null));

        // Linked to the user's OWN ticket → subject shown.
        $myTicket = $this->ticket(['assigned_to' => $user->id]);
        $task2 = Task::create([
            'title' => 'Triage mine', 'type' => 'task', 'status' => 'todo',
            'ticket_id' => $myTicket->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
        ]);
        $this->actingAs($user)->get("/activities/{$task2->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('task.ticket.id', $myTicket->id));
    }
}
