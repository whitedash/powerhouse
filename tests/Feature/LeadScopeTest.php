<?php

namespace Tests\Feature;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Lead;
use App\Models\RoleScope;
use App\Models\Task;
use App\Models\User;
use App\Support\ScopeEnforcer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3b-iii: scope enforcement on Leads via the shared ScopeEnforcer seam.
 * Staff is seeded All (the existing suite proves the access-identical path), so
 * these cover the NEW behaviour:
 *
 *   - Assigned filters the lead list to leads.assigned_to === me.
 *   - assigned_to is a NULLABLE FK, so a lead assigned to NOBODY is NOT "mine":
 *     an Assigned user sees neither other people's leads NOR unassigned ones.
 *   - Per-item: every Lead action method blocks a lead not assigned to the user
 *     by direct ID (the viewAny(Company)/review(Lead) gates don't check
 *     assignment, so this is the only thing stopping IDOR under Assigned).
 *   - None is walled off entirely; super_admin/All are unfiltered.
 */
class LeadScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * companies.access clears the viewAny(Company) gate the lead pages ride;
     * leads.manage clears the review(Lead) gate (approve/reject) — so the tests
     * isolate the SCOPE behaviour, not those permission gates.
     */
    private function leadsRole(string $name, AccessScope $leads, ?AccessScope $tasks = null): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->givePermissionTo('companies.access');
        $role->givePermissionTo('leads.manage');
        RoleScope::create(['role_id' => $role->id, 'area' => ScopeArea::Leads->value, 'scope' => $leads->value]);
        // The Tasks↔Leads boundary tests need a Tasks scope too (to clear the
        // Tasks section/item gates so the LEADS gate is what's exercised).
        if ($tasks !== null) {
            RoleScope::create(['role_id' => $role->id, 'area' => ScopeArea::Tasks->value, 'scope' => $tasks->value]);
        }

        return $role;
    }

    private function userWith(string $roleName, AccessScope $leads, ?AccessScope $tasks = null): User
    {
        $user = User::factory()->create(); // enum staff → clears EnsureRole
        $user->syncRoles([$this->leadsRole($roleName, $leads, $tasks)->name]);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function lead(array $attrs): Lead
    {
        return Lead::create(array_merge([
            'first_name' => 'L'.uniqid(),
            'status' => 'new',
            'source' => 'manual',
        ], $attrs));
    }

    // ─── Resolution semantics ───

    public function test_effective_scope_resolves_for_leads(): void
    {
        $this->assertSame(AccessScope::All, ScopeEnforcer::effectiveScope(
            User::factory()->superAdmin()->create()->fresh(), ScopeArea::Leads,
        ));
        $this->assertSame(AccessScope::All, ScopeEnforcer::effectiveScope(
            User::factory()->create()->fresh(), ScopeArea::Leads, // seeded staff → All
        ));
        $this->assertSame(AccessScope::Assigned, ScopeEnforcer::effectiveScope(
            $this->userWith('Leads Assigned', AccessScope::Assigned), ScopeArea::Leads,
        ));
        $this->assertSame(AccessScope::None, ScopeEnforcer::effectiveScope(
            $this->userWith('Leads None', AccessScope::None), ScopeArea::Leads,
        ));
    }

    // ─── STRICT assigned, including NULL-assigned ───

    public function test_assigned_is_strictly_the_assignee_and_excludes_null(): void
    {
        $user = $this->userWith('Leads Strict', AccessScope::Assigned);
        $other = User::factory()->create();

        $mine = $this->lead(['created_by' => $other->id, 'assigned_to' => $user->id]);
        $theirs = $this->lead(['created_by' => $other->id, 'assigned_to' => $other->id]);
        $unassigned = $this->lead(['created_by' => $other->id, 'assigned_to' => null]);

        $this->assertTrue(ScopeEnforcer::allows($user, ScopeArea::Leads, $mine));
        $this->assertFalse(ScopeEnforcer::allows($user, ScopeArea::Leads, $theirs));
        $this->assertFalse(ScopeEnforcer::allows($user, ScopeArea::Leads, $unassigned)); // NULL ≠ mine

        $ids = ScopeEnforcer::applyToList(Lead::query(), $user, ScopeArea::Leads)->pluck('id');
        $this->assertEqualsCanonicalizing([$mine->id], $ids->all());
    }

    // ─── Index list filtered ───

    public function test_index_kpis_follow_effective_scope(): void
    {
        // 3b-iv follow-up: the KPI chips reflect the user's effective scope, not
        // team totals — Assigned → "my pipeline"; All → whole pipeline.
        $user = $this->userWith('Leads KPI', AccessScope::Assigned);
        $other = User::factory()->create();
        $this->lead(['created_by' => $other->id, 'assigned_to' => $user->id, 'status' => 'new']);
        $this->lead(['created_by' => $other->id, 'assigned_to' => $other->id, 'status' => 'new']);
        $this->lead(['created_by' => $other->id, 'assigned_to' => $other->id, 'status' => 'new']);

        $this->actingAs($user)->get('/leads')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('summary.total', 1)->where('summary.new', 1));

        $staff = User::factory()->create(); // All → unchanged whole-pipeline totals
        $this->actingAs($staff)->get('/leads')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('summary.total', 3)->where('summary.new', 3));
    }

    public function test_index_lists_only_assigned_leads_and_all_sees_everything(): void
    {
        $user = $this->userWith('Leads Idx', AccessScope::Assigned);
        $other = User::factory()->create();

        $mine = $this->lead(['created_by' => $other->id, 'assigned_to' => $user->id]);
        $this->lead(['created_by' => $other->id, 'assigned_to' => $other->id]);
        $this->lead(['created_by' => $other->id, 'assigned_to' => null]);

        $this->actingAs($user)->get('/leads')->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Internal/Leads/Index')
                ->has('leads', 1)
                ->where('leads.0.id', $mine->id));

        // Staff = All → every non-converted lead.
        $staff = User::factory()->create();
        $this->actingAs($staff)->get('/leads')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('leads', 3));

        // super_admin = All (NOT query-filtered).
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->get('/leads')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('leads', 3));
    }

    // ─── Per-item direct-ID blocking across every action method ───

    public function test_assigned_blocks_every_action_on_a_non_assigned_lead(): void
    {
        $user = $this->userWith('Leads Item', AccessScope::Assigned);
        $other = User::factory()->create();
        $theirs = $this->lead(['created_by' => $other->id, 'assigned_to' => $other->id]);

        $this->actingAs($user)->get("/leads/{$theirs->id}")->assertForbidden();
        $this->actingAs($user)->put("/leads/{$theirs->id}", [])->assertForbidden();
        $this->actingAs($user)->post("/leads/{$theirs->id}/status", ['status' => 'contacted'])->assertForbidden();
        $this->actingAs($user)->post("/leads/{$theirs->id}/convert", [])->assertForbidden();
        $this->actingAs($user)->delete("/leads/{$theirs->id}")->assertForbidden();
        // Referral approve/reject ride the review(Lead) permission (granted to
        // the role) — so a 403 here is the SCOPE gate, not the permission.
        $this->actingAs($user)->post("/leads/{$theirs->id}/referral/approve")->assertForbidden();
        $this->actingAs($user)->post("/leads/{$theirs->id}/referral/reject", ['reason' => 'x'])->assertForbidden();
    }

    public function test_assigned_blocks_a_null_assigned_lead_by_direct_id(): void
    {
        $user = $this->userWith('Leads NullItem', AccessScope::Assigned);
        $other = User::factory()->create();
        $unassigned = $this->lead(['created_by' => $other->id, 'assigned_to' => null]);

        $this->actingAs($user)->get("/leads/{$unassigned->id}")->assertForbidden();
        $this->actingAs($user)->put("/leads/{$unassigned->id}", [])->assertForbidden();
        $this->actingAs($user)->delete("/leads/{$unassigned->id}")->assertForbidden();
    }

    public function test_assigned_can_open_its_own_lead(): void
    {
        $user = $this->userWith('Leads OwnItem', AccessScope::Assigned);
        $mine = $this->lead(['created_by' => User::factory()->create()->id, 'assigned_to' => $user->id]);

        $this->actingAs($user)->get("/leads/{$mine->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Internal/Leads/Show'));
    }

    // ─── None: no access, no creation ───

    public function test_none_blocks_section_item_and_creation(): void
    {
        $user = $this->userWith('Leads None 2', AccessScope::None);
        $lead = $this->lead(['created_by' => $user->id, 'assigned_to' => $user->id]);

        $this->actingAs($user)->get('/leads')->assertForbidden();           // section gate
        $this->actingAs($user)->get("/leads/{$lead->id}")->assertForbidden(); // item gate
        $this->actingAs($user)->post('/leads', [])->assertForbidden();        // creation gate (before validation)
    }

    public function test_staff_all_scope_can_create_access_identical(): void
    {
        $staff = User::factory()->create(); // All

        $this->actingAs($staff)->post('/leads', [
            'first_name' => 'Pat',
            'last_name' => 'Prospect',
            'source' => 'manual',
            'status' => 'new',
        ])->assertRedirect(); // success, no 403

        $this->assertDatabaseHas('leads', ['first_name' => 'Pat', 'last_name' => 'Prospect']);
    }

    // ─── Tasks↔Leads boundary (adversarial review) ───

    public function test_task_link_options_lead_picker_is_scoped(): void
    {
        // The task form's "Link to lead" picker must not enumerate every lead.
        $user = $this->userWith('Leads Picker', AccessScope::Assigned);
        $other = User::factory()->create();
        $mine = $this->lead(['created_by' => $other->id, 'assigned_to' => $user->id]);
        $this->lead(['created_by' => $other->id, 'assigned_to' => $other->id]);

        $res = $this->actingAs($user)->getJson('/tasks/link-options?type=lead');
        $res->assertOk();
        $this->assertEqualsCanonicalizing([$mine->id], collect($res->json('options'))->pluck('id')->all());

        // super_admin sees the full picker.
        $admin = User::factory()->superAdmin()->create();
        $this->assertCount(2, $this->actingAs($admin)->getJson('/tasks/link-options?type=lead')->json('options'));
    }

    public function test_task_cannot_be_linked_to_an_out_of_scope_lead(): void
    {
        // Tasks=All clears the task section gate so the LEADS guard is what fires.
        $user = $this->userWith('Leads TaskLink', AccessScope::Assigned, AccessScope::All);
        $other = User::factory()->create();
        $theirLead = $this->lead(['created_by' => $other->id, 'assigned_to' => $other->id]);

        $this->actingAs($user)->post('/tasks', [
            'type' => 'task',
            'title' => 'Sneak',
            'lead_id' => $theirLead->id,
            'due_at' => now()->addDay()->toDateString(),
        ])->assertForbidden();

        $this->assertDatabaseMissing('tasks', ['title' => 'Sneak']);
    }

    public function test_lead_linked_task_show_rides_the_lead_scope(): void
    {
        $user = $this->userWith('Leads TaskShow', AccessScope::Assigned, AccessScope::Assigned);
        $other = User::factory()->create();

        // Task assigned to the user (clears Tasks scope) but linked to a lead
        // that ISN'T theirs → blocked, so the eager-loaded lead can't leak.
        $theirLead = $this->lead(['created_by' => $other->id, 'assigned_to' => $other->id]);
        $blocked = Task::create([
            'title' => 'Foreign lead activity', 'type' => 'task', 'status' => 'todo',
            'lead_id' => $theirLead->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
        ]);
        $this->actingAs($user)->get("/activities/{$blocked->id}")->assertForbidden();

        // Linked to the user's OWN lead → viewable.
        $myLead = $this->lead(['created_by' => $other->id, 'assigned_to' => $user->id]);
        $ok = Task::create([
            'title' => 'My lead activity', 'type' => 'task', 'status' => 'todo',
            'lead_id' => $myLead->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
        ]);
        $this->actingAs($user)->get("/activities/{$ok->id}")->assertOk();
    }

    public function test_form_submissions_hide_non_assigned_lead_identity(): void
    {
        // The form submissions table cross-references the CRM lead each
        // submission became — that linkage must follow Leads scope.
        $user = $this->userWith('Leads Forms', AccessScope::Assigned);
        // Reaching the submissions page now requires forms.view_submissions
        // (Issue-A: it returns submission PII). This test's purpose is the
        // lead-identity hiding WITHIN the page under Leads scope, so grant the
        // access permission and keep exercising that.
        $user->givePermissionTo('forms.view_submissions');
        $user = $user->fresh();
        $other = User::factory()->create();
        $mine = $this->lead(['created_by' => $other->id, 'assigned_to' => $user->id]);
        $theirs = $this->lead(['created_by' => $other->id, 'assigned_to' => $other->id]);

        $form = Form::create([
            'name' => 'Lead Form',
            'slug' => 'lf-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'submit_button_text' => 'Submit',
            'webhook_secret' => Str::random(32),
            'created_by' => $other->id,
        ]);
        FormSubmission::create(['form_id' => $form->id, 'lead_id' => $mine->id, 'data' => ['n' => 1], 'status' => 'new']);
        FormSubmission::create(['form_id' => $form->id, 'lead_id' => $theirs->id, 'data' => ['n' => 2], 'status' => 'new']);

        // Assigned: both submissions still listed, but only the user's own lead
        // is cross-referenced — the other's lead identity is null.
        $this->actingAs($user)->get("/forms/{$form->id}/submissions")->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('submissions', 2)
                ->where('submissions', fn ($subs) => $subs->whereNotNull('lead')->count() === 1
                    && $subs->firstWhere('lead.id', $mine->id) !== null
                    && $subs->firstWhere('lead.id', $theirs->id) === null));

        // super_admin sees every lead linkage.
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->get("/forms/{$form->id}/submissions")->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('submissions', fn ($subs) => $subs->whereNotNull('lead')->count() === 2));
    }
}
