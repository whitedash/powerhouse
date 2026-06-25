<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 2 — expenses.manage (+ the markPaid money gap).
 *
 * Expense + supplier mutations now require expenses.manage via route middleware,
 * composing with the existing per-record checks. markPaid additionally requires
 * expenses.approve (decision: reaching status=paid is approval-tier on every
 * path, matching the Issue-A store/update guard). approve() keeps its
 * expenses.approve-only gate (separation of duties); reads stay on customers.access.
 */
class ExpensesManageEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'exp_'.uniqid(), 'guard_name' => 'web']);
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

    private function makeExpense(string $status = 'pending'): Expense
    {
        return Expense::create([
            'category' => 'software',
            'description' => 'Existing expense',
            'amount' => 100,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total' => 100,
            'expense_date' => now()->toDateString(),
            'status' => $status,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function expensePayload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'software',
            'description' => 'New expense',
            'amount' => 100,
            'expense_date' => now()->toDateString(),
        ], $overrides);
    }

    /** The 7 mutating expense+supplier routes, [verb, url]. */
    private function mutationRoutes(): array
    {
        return [
            ['post', '/expenses'],
            ['put', '/expenses/1'],
            ['delete', '/expenses/1'],
            ['post', '/expenses/1/mark-paid'],
            ['post', '/suppliers'],
            ['put', '/suppliers/1'],
            ['delete', '/suppliers/1'],
        ];
    }

    public function test_all_mutations_403_without_expenses_manage(): void
    {
        // Holds customers.access (the old, too-permissive gate) but NOT expenses.manage.
        $user = $this->userWith(['customers.access']);

        foreach ($this->mutationRoutes() as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url)
                ->assertForbidden(); // route middleware blocks before the controller
        }
    }

    public function test_expense_create_succeeds_with_manage(): void
    {
        $user = $this->userWith(['customers.access', 'expenses.manage']);

        $this->actingAs($user)
            ->post('/expenses', $this->expensePayload(['description' => 'Created']))
            ->assertRedirect();
        $this->assertDatabaseHas('expenses', ['description' => 'Created', 'status' => 'pending']);
    }

    public function test_expense_update_and_destroy_succeed_with_manage(): void
    {
        $user = $this->userWith(['customers.access', 'expenses.manage']);
        $expense = $this->makeExpense();

        $this->actingAs($user)
            ->put("/expenses/{$expense->id}", $this->expensePayload(['status' => 'pending', 'description' => 'Edited']))
            ->assertRedirect();
        $this->assertSame('Edited', $expense->fresh()->description);

        $this->actingAs($user)->delete("/expenses/{$expense->id}")->assertRedirect();
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    public function test_supplier_create_succeeds_with_manage(): void
    {
        $user = $this->userWith(['customers.access', 'expenses.manage']);

        $this->actingAs($user)->post('/suppliers', [
            'name' => 'Acme Supplies',
            'type' => 'software',
            'default_vat_rate' => 20,
        ])->assertRedirect();
        $this->assertDatabaseHas('suppliers', ['name' => 'Acme Supplies']);
    }

    public function test_markpaid_succeeds_with_manage_and_approve(): void
    {
        $user = $this->userWith(['customers.access', 'expenses.manage', 'expenses.approve']);
        $expense = $this->makeExpense('approved');

        $this->actingAs($user)->post("/expenses/{$expense->id}/mark-paid")->assertRedirect();
        $this->assertSame('paid', $expense->fresh()->status);
    }

    public function test_superadmin_bypasses_via_gate_before(): void
    {
        $admin = $this->bareSuperAdmin();
        $expense = $this->makeExpense('approved');

        $this->actingAs($admin)->post('/expenses', $this->expensePayload())->assertRedirect();
        $this->actingAs($admin)->post("/expenses/{$expense->id}/mark-paid")->assertRedirect();
        $this->assertSame('paid', $expense->fresh()->status);
    }

    public function test_composition_manage_without_approve_can_create_pending_but_not_approve_or_pay(): void
    {
        // expenses.manage (touch records) but NOT expenses.approve (set approved/paid).
        $user = $this->userWith(['customers.access', 'expenses.manage']);

        // Can create a normal pending expense.
        $this->actingAs($user)
            ->post('/expenses', $this->expensePayload(['description' => 'Pending one', 'status' => 'pending']))
            ->assertRedirect();
        $this->assertDatabaseHas('expenses', ['description' => 'Pending one', 'status' => 'pending']);

        // Cannot create directly-approved (the store expenses.approve guard fires).
        $this->actingAs($user)
            ->post('/expenses', $this->expensePayload(['description' => 'Sneak approved', 'status' => 'approved']))
            ->assertForbidden();
        $this->assertDatabaseMissing('expenses', ['description' => 'Sneak approved']);

        // Cannot transition an existing expense to approved via update.
        $expense = $this->makeExpense('pending');
        $this->actingAs($user)
            ->put("/expenses/{$expense->id}", $this->expensePayload(['status' => 'approved']))
            ->assertForbidden();
        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_markpaid_blocked_with_manage_without_approve(): void
    {
        // Proves markPaid's distinct approve-tier: route expenses.manage passes,
        // the in-method expenses.approve check blocks.
        $user = $this->userWith(['customers.access', 'expenses.manage']);
        $expense = $this->makeExpense('approved');

        $this->actingAs($user)->post("/expenses/{$expense->id}/mark-paid")->assertForbidden();
        $this->assertSame('approved', $expense->fresh()->status); // not paid
    }
}
