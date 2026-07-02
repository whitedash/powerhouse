<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 3 — proposals (access + manage).
 *
 * Reads (index/show/pdf/accepted-pdf) now require proposals.access; mutations
 * (store/destroy/send/convert + the payment-schedule store) require
 * proposals.manage. Both compose with the in-method viewAny(Company) =
 * companies.access. super_admin bypasses via Gate::before.
 */
class ProposalsEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'prop_'.uniqid(), 'guard_name' => 'web']);
        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
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

    private function makeProposal(string $status = 'draft'): Proposal
    {
        $customer = Company::create(['name' => 'Acme '.uniqid()]);

        return Proposal::create([
            'customer_id' => $customer->id,
            'reference' => 'PROP-'.strtoupper(substr(uniqid(), -8)),
            'title' => 'Proposal '.uniqid(),
            'status' => $status,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function readRoutes(): array
    {
        return [['get', '/proposals'], ['get', '/proposals/1'], ['get', '/proposals/1/pdf'], ['get', '/proposals/1/accepted-pdf']];
    }

    private function mutationRoutes(): array
    {
        return [
            ['post', '/proposals'],
            ['delete', '/proposals/1'],
            ['post', '/proposals/1/send'],
            ['post', '/proposals/1/convert'],
            ['post', '/payment-schedules'],
        ];
    }

    // ─── proposals.access (reads) ───

    public function test_reads_403_without_proposals_access(): void
    {
        // companies.access alone (the old gate) no longer admits the reads.
        $user = $this->userWith(['companies.access']);
        foreach ($this->readRoutes() as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }
    }

    public function test_index_read_ok_with_proposals_access(): void
    {
        $user = $this->userWith(['companies.access', 'proposals.access']);
        $this->actingAs($user)->get('/proposals')->assertOk();
    }

    public function test_show_read_ok_with_proposals_access(): void
    {
        $user = $this->userWith(['companies.access', 'proposals.access']);
        $proposal = $this->makeProposal();
        $this->actingAs($user)->get("/proposals/{$proposal->id}")->assertOk();
    }

    // ─── proposals.manage (mutations) ───

    public function test_all_mutations_403_without_proposals_manage(): void
    {
        // Holds companies.access AND proposals.access (reads) but NOT proposals.manage.
        $user = $this->userWith(['companies.access', 'proposals.access']);
        foreach ($this->mutationRoutes() as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }
    }

    public function test_destroy_draft_succeeds_with_proposals_manage(): void
    {
        $user = $this->userWith(['companies.access', 'proposals.manage']);
        $proposal = $this->makeProposal('draft');

        $this->actingAs($user)->delete("/proposals/{$proposal->id}")->assertRedirect();
        $this->assertDatabaseMissing('proposals', ['id' => $proposal->id]);
    }

    public function test_mutations_authorized_with_proposals_manage(): void
    {
        // Past the proposals.manage gate the requests reach the controller (not 403).
        $user = $this->userWith(['companies.access', 'proposals.manage']);
        $proposal = $this->makeProposal('draft');

        $this->assertNotSame(403, $this->actingAs($user)->post('/proposals', [])->getStatusCode());           // 422 validation
        $this->assertNotSame(403, $this->actingAs($user)->post("/proposals/{$proposal->id}/send")->getStatusCode());
        $this->assertNotSame(403, $this->actingAs($user)->post('/payment-schedules', [])->getStatusCode());    // 422 validation
    }

    // ─── super_admin + composition ───

    public function test_superadmin_bypasses_reads_and_mutations(): void
    {
        $admin = $this->bareSuperAdmin();
        $proposal = $this->makeProposal('draft');

        $this->actingAs($admin)->get('/proposals')->assertOk();
        $this->actingAs($admin)->delete("/proposals/{$proposal->id}")->assertRedirect();
        $this->assertDatabaseMissing('proposals', ['id' => $proposal->id]);
    }

    public function test_proposals_access_does_not_grant_manage(): void
    {
        // A reader (proposals.access, no manage) is blocked from every mutation —
        // proves the two permissions are independent (access ≠ manage).
        $user = $this->userWith(['companies.access', 'proposals.access']);
        foreach ($this->mutationRoutes() as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }
    }
}
