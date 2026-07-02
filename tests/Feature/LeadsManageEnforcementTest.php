<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\RoleScope;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 5 — leads.manage (CRUD + convert).
 *
 * The 5 lead mutations (store/update/updateStatus/convert/destroy) now require
 * leads.manage (route middleware), composing with the in-method Leads scope.
 * convert additionally requires companies.manage (it mints a Company — not a
 * back-door to customer creation). approve/reject keep their existing
 * LeadPolicy::review (leads.manage) gate, NOT double-gated. super_admin bypasses.
 */
class LeadsManageEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @param array<string,string> $scopes */
    private function userWith(array $permissions, array $scopes = ['leads' => 'all']): User
    {
        $role = Role::create(['name' => 'lead_'.uniqid(), 'guard_name' => 'web']);
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

    /** @param array<string,mixed> $attrs */
    private function makeLead(array $attrs = []): Lead
    {
        $owner = User::factory()->create()->id;

        return Lead::create(array_merge([
            'first_name' => 'Lead'.uniqid(),
            'status' => 'new',
            'source' => 'manual',
            'created_by' => $owner,
            'assigned_to' => $owner,
        ], $attrs));
    }

    /** @param array<string,mixed> $overrides */
    private function convertPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Converted Co '.uniqid(),
            'type' => 'restaurant',
            'address_line1' => '1 High St',
            'city' => 'London',
            'postcode' => 'E1 1AA',
        ], $overrides);
    }

    public function test_all_five_mutations_403_without_leads_manage(): void
    {
        // companies.access + Leads scope All (the old gate) but NOT leads.manage.
        $user = $this->userWith(['companies.access']);
        foreach ([['post', '/leads'], ['put', '/leads/1'], ['post', '/leads/1/status'], ['post', '/leads/1/convert'], ['delete', '/leads/1']] as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)->assertForbidden();
        }
    }

    public function test_destroy_succeeds_with_leads_manage(): void
    {
        $user = $this->userWith(['companies.access', 'leads.manage']);
        $lead = $this->makeLead();

        $this->actingAs($user)->delete("/leads/{$lead->id}")->assertRedirect();
        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
    }

    public function test_superadmin_bypasses_via_gate_before(): void
    {
        $lead = $this->makeLead();
        $this->actingAs($this->bareSuperAdmin())->delete("/leads/{$lead->id}")->assertRedirect();
        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
    }

    public function test_composition_leads_manage_without_scope_is_blocked(): void
    {
        // leads.manage passes the route middleware, but Leads scope None → the
        // in-method authorizeScopeItem still 403s. Both required.
        $user = $this->userWith(['companies.access', 'leads.manage'], []); // no leads scope row → None
        $lead = $this->makeLead();

        $this->actingAs($user)->put("/leads/{$lead->id}", ['first_name' => 'Edited'])->assertForbidden();
    }

    // ─── convert: the cross-section decision (leads.manage + companies.manage) ───

    public function test_convert_succeeds_with_leads_and_customers_manage(): void
    {
        $user = $this->userWith(['companies.access', 'companies.manage', 'leads.manage']);
        $lead = $this->makeLead();

        $this->actingAs($user)->post("/leads/{$lead->id}/convert", $this->convertPayload(['name' => 'Won Company']))
            ->assertRedirect();
        $this->assertDatabaseHas('customers', ['name' => 'Won Company']);
        $this->assertNotNull($lead->fresh()->customer_id); // lead linked to the new customer
    }

    public function test_convert_blocked_with_leads_manage_but_not_customers_manage(): void
    {
        // Holds leads.manage (passes the route gate) + companies.access, but NOT
        // companies.manage → the in-method create-Company gate 403s. Proves
        // convert isn't a back-door to customer creation.
        $user = $this->userWith(['companies.access', 'leads.manage']);
        $lead = $this->makeLead();

        $this->actingAs($user)->post("/leads/{$lead->id}/convert", $this->convertPayload(['name' => 'Sneak Company']))
            ->assertForbidden();
        $this->assertDatabaseMissing('customers', ['name' => 'Sneak Company']);
        $this->assertNull($lead->fresh()->customer_id); // not converted
    }

    // ─── approve/reject unchanged (leads.manage via LeadPolicy::review, not double-gated) ───

    public function test_approve_still_blocked_without_leads_manage(): void
    {
        $user = $this->userWith(['companies.access']); // Leads scope All, no leads.manage
        $lead = $this->makeLead(['referral_status' => 'pending_review']);

        $this->actingAs($user)->post("/leads/{$lead->id}/referral/approve")->assertForbidden();
    }

    public function test_approve_authorized_with_leads_manage(): void
    {
        $user = $this->userWith(['companies.access', 'leads.manage']);
        $lead = $this->makeLead(['referral_status' => 'pending_review']);

        // Past LeadPolicy::review (leads.manage) + Leads scope → reaches the
        // service (not a 403). Confirms approve still works and isn't double-gated.
        $this->assertNotSame(403, $this->actingAs($user)->post("/leads/{$lead->id}/referral/approve")->getStatusCode());
    }
}
