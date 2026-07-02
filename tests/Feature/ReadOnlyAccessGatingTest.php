<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 10 — read-only access gating batch.
 *
 * Section SHELL/read routes now gate their own .access permission (route
 * middleware), replacing coarse-role / companies.access read-gating:
 *  - settings.access  → GET /settings (shell only; sub-pages keep their own gates)
 *  - expenses.access  → GET /expenses, /expenses/{id}/receipt, /suppliers
 *                       (compose with the in-method companies.access viewAny)
 *  - referrers.access → GET /referrers, /referrers/{id} (clean), /referrals
 *                       (composes with in-method companies.access viewAny)
 *
 * proposals.access (step 3) and hosting.access (step 6) were already gated — not
 * retouched here. super_admin bypasses via Gate::before. Mutations + already-gated
 * sub-actions are untouched.
 */
class ReadOnlyAccessGatingTest extends TestCase
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
        $role = Role::create(['name' => 'ro_'.uniqid(), 'guard_name' => 'web']);
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

    // ─── settings.access (shell only) ───

    public function test_settings_shell_403_without_settings_access(): void
    {
        $this->actingAs($this->userWith([]))->get('/settings')->assertForbidden();
    }

    public function test_settings_shell_succeeds_with_settings_access(): void
    {
        $this->actingAs($this->userWith(['settings.access']))->get('/settings')->assertOk();
    }

    public function test_settings_access_does_not_grant_sub_actions(): void
    {
        // settings.access reaches the shell but NOT the per-capability sub-pages.
        $this->actingAs($this->userWith(['settings.access']))->get('/settings/notifications')->assertForbidden();
    }

    // ─── expenses.access (composes with in-method companies.access viewAny) ───

    public function test_expenses_index_403_without_expenses_access(): void
    {
        // Holds the OLD read gate (companies.access) but not expenses.access → blocked.
        $this->actingAs($this->userWith(['companies.access']))->get('/expenses')->assertForbidden();
    }

    public function test_expenses_reads_succeed_with_expenses_access(): void
    {
        // Composition: route expenses.access + in-method companies.access (viewAny).
        $user = $this->userWith(['expenses.access', 'companies.access']);
        $this->actingAs($user)->get('/expenses')->assertOk();
        $this->actingAs($user)->get('/suppliers')->assertOk();
    }

    public function test_expenses_access_does_not_grant_mutations(): void
    {
        $user = $this->userWith(['expenses.access', 'companies.access']);
        $this->actingAs($user)->post('/expenses')->assertForbidden(); // needs expenses.manage
    }

    // ─── referrers.access ───

    public function test_referrers_reads_403_without_referrers_access(): void
    {
        $this->actingAs($this->userWith([]))->get('/referrers')->assertForbidden();
        // /referrals: even with the old companies.access gate, the route now needs referrers.access.
        $this->actingAs($this->userWith(['companies.access']))->get('/referrals')->assertForbidden();
    }

    public function test_referrers_reads_succeed_with_referrers_access(): void
    {
        // referrers index/show are clean section-only gates.
        $this->actingAs($this->userWith(['referrers.access']))->get('/referrers')->assertOk();
        // /referrals composes the in-method companies.access viewAny.
        $this->actingAs($this->userWith(['referrers.access', 'companies.access']))->get('/referrals')->assertOk();
    }

    public function test_referrers_access_does_not_grant_mutations(): void
    {
        $this->actingAs($this->userWith(['referrers.access']))->post('/referrers')->assertForbidden(); // needs referrers.manage
    }

    // ─── super_admin bypass ───

    public function test_superadmin_bypasses_all_read_gates(): void
    {
        $admin = $this->bareSuperAdmin();
        $this->actingAs($admin)->get('/settings')->assertOk();
        $this->actingAs($admin)->get('/expenses')->assertOk();
        $this->actingAs($admin)->get('/referrers')->assertOk();
        $this->actingAs($admin)->get('/referrals')->assertOk();
    }
}
