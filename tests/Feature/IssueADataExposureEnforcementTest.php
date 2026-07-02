<?php

namespace Tests\Feature;

use App\Models\BillingEntity;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Form;
use App\Models\Milestone;
use App\Models\PaymentSchedule;
use App\Models\PaymentScheduleItem;
use App\Models\Project;
use App\Models\RoleScope;
use App\Models\User;
use App\Models\WebhookDelivery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Issue-A RED cluster — each fix makes a permission actually enforce its action
 * server-side (the cutover had left these gated on customers.* or ungated).
 *
 * Each guard proves the enforcement now BITES: a user WITHOUT the permission is
 * 403'd at the action; a holder succeeds; super_admin bypasses via Gate::before.
 * For the two invoice side-doors the gate fires before model lookup, so the
 * "with" case asserts the request gets PAST authorization (422/404), not a full
 * happy-path build.
 */
class IssueADataExposureEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Staff-enum user (passes role:super_admin,staff) holding ONLY $permissions. */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'custom_'.uniqid(), 'guard_name' => 'web']);
        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }
        $user = User::factory()->create();   // enum staff
        $user->syncRoles([$role->name]);     // replace the auto-assigned staff role

        // Return a FRESH instance: factory's afterCreating eager-loads
        // roles=[staff]; syncRoles updates the DB but leaves that relation
        // stale in memory, and actingAs() would otherwise hand the request a
        // user still carrying the staff role's permissions.
        return $user->fresh();
    }

    /** super_admin stripped of every role/permission — proves the bypass is enum-driven. */
    private function bareSuperAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create();
        $admin->syncRoles([]);
        $admin->syncPermissions([]);

        return $admin;
    }

    private function makeForm(): Form
    {
        return Form::create([
            'name' => 'Contact form',
            'slug' => 'contact-'.uniqid(),
            'status' => 'active',
            'webhook_secret' => str_repeat('a', 64),
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function makeExpense(string $status): Expense
    {
        return Expense::create([
            'category' => 'software',
            'description' => 'Original description',
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
            'description' => 'Edited description',
            'amount' => 100,
            'expense_date' => now()->toDateString(),
        ], $overrides);
    }

    // ─────────── 1. forms.view_submissions (PII / GDPR) ───────────

    public function test_forms_submissions_403_without_permission(): void
    {
        // Has forms access but NOT forms.view_submissions.
        $user = $this->userWith(['forms.access']);
        $this->actingAs($user)->get('/forms/1/submissions')->assertForbidden();
    }

    public function test_forms_submissions_ok_with_permission(): void
    {
        $form = $this->makeForm();
        $staff = User::factory()->create(); // staff role holds forms.view_submissions
        $this->actingAs($staff)->get("/forms/{$form->id}/submissions")->assertOk();
    }

    public function test_forms_submissions_superadmin_bypass(): void
    {
        $form = $this->makeForm();
        $this->actingAs($this->bareSuperAdmin())->get("/forms/{$form->id}/submissions")->assertOk();
    }

    // ─────────── 2. expenses.approve via the update side-door ───────────

    public function test_expense_update_to_approved_403_without_approve(): void
    {
        $user = $this->userWith(['companies.access']); // reaches update, lacks expenses.approve
        $expense = $this->makeExpense('pending');

        $this->actingAs($user)
            ->put("/expenses/{$expense->id}", $this->expensePayload(['status' => 'approved']))
            ->assertForbidden();

        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_expense_update_to_paid_403_without_approve(): void
    {
        $user = $this->userWith(['companies.access']);
        $expense = $this->makeExpense('pending');

        $this->actingAs($user)
            ->put("/expenses/{$expense->id}", $this->expensePayload(['status' => 'paid']))
            ->assertForbidden();

        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_expense_update_detail_edit_without_status_change_still_works(): void
    {
        // Editing details (status unchanged) now requires expenses.manage (sprint
        // step 2) but NOT expenses.approve (no status change).
        $user = $this->userWith(['companies.access', 'expenses.manage']);
        $expense = $this->makeExpense('pending');

        $this->actingAs($user)
            ->put("/expenses/{$expense->id}", $this->expensePayload(['status' => 'pending', 'description' => 'New detail']))
            ->assertRedirect();

        $this->assertSame('New detail', $expense->fresh()->description);
        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_expense_update_to_approved_succeeds_with_approve(): void
    {
        $user = $this->userWith(['companies.access', 'expenses.manage', 'expenses.approve']);
        $expense = $this->makeExpense('pending');

        $this->actingAs($user)
            ->put("/expenses/{$expense->id}", $this->expensePayload(['status' => 'approved']))
            ->assertRedirect();

        $this->assertSame('approved', $expense->fresh()->status);
    }

    public function test_expense_update_to_approved_superadmin_bypass(): void
    {
        $expense = $this->makeExpense('pending');

        $this->actingAs($this->bareSuperAdmin())
            ->put("/expenses/{$expense->id}", $this->expensePayload(['status' => 'approved']))
            ->assertRedirect();

        $this->assertSame('approved', $expense->fresh()->status);
    }

    // ─────────── 3. invoices.manage side-doors (project + payment schedule) ───────────

    public function test_project_generate_invoice_403_without_invoices_manage(): void
    {
        // companies.access passes the first gate; the new create-Invoice gate must block.
        $user = $this->userWith(['companies.access', 'projects.manage']);
        $this->actingAs($user)->post('/projects/1/invoice', [])->assertForbidden();
    }

    public function test_project_generate_invoice_authorized_with_invoices_manage(): void
    {
        $staff = User::factory()->create(); // holds companies.access + invoices.manage + projects scope All
        // Past both gates → findOrFail on a missing project (404), NOT 403. Proves
        // the new invoices.manage gate does not over-block a permitted user.
        $this->actingAs($staff)->post('/projects/999999/invoice', [])->assertNotFound();
    }

    public function test_payment_schedule_trigger_403_without_invoices_manage(): void
    {
        $user = $this->userWith(['companies.access']);
        $this->actingAs($user)->post('/payment-schedules/items/1/trigger', [])->assertForbidden();
    }

    public function test_payment_schedule_trigger_authorized_with_invoices_manage(): void
    {
        $staff = User::factory()->create();
        // Past both gates → findOrFail on a missing item (404), NOT 403.
        $this->actingAs($staff)->post('/payment-schedules/items/1/trigger', [])->assertNotFound();
    }

    public function test_invoice_side_doors_superadmin_bypass(): void
    {
        $admin = $this->bareSuperAdmin();
        $this->actingAs($admin)->post('/projects/1/invoice', [])->assertNotFound();      // past gates → 404
        $this->actingAs($admin)->post('/payment-schedules/items/1/trigger', [])->assertNotFound();
    }

    // ─────────── 4. settings.integrations — webhook retry ───────────

    public function test_webhook_retry_403_without_settings_integrations(): void
    {
        $staff = User::factory()->create(); // staff LACKS settings.integrations (super-admin-only)
        $this->actingAs($staff)->post('/webhooks/deliveries/1/retry')->assertForbidden();
    }

    public function test_webhook_retry_succeeds_with_settings_integrations(): void
    {
        Queue::fake();
        $user = $this->userWith(['settings.integrations']);
        $delivery = WebhookDelivery::create([
            'event_type' => 'customer.test',
            'product_slug' => 'maavelus',
            'payload' => ['x' => 1],
            'target_url' => 'https://example.com/webhook',
            'signature' => str_repeat('b', 64),
            'status' => 'failed',
            'attempts' => 1,
            'max_attempts' => 3,
        ]);

        $this->actingAs($user)->post("/webhooks/deliveries/{$delivery->id}/retry")->assertRedirect();
        $this->assertSame('pending', $delivery->fresh()->status);
    }

    public function test_webhook_retry_superadmin_bypass(): void
    {
        Queue::fake();
        $delivery = WebhookDelivery::create([
            'event_type' => 'customer.test',
            'product_slug' => 'maavelus',
            'payload' => ['x' => 1],
            'target_url' => 'https://example.com/webhook',
            'signature' => str_repeat('c', 64),
            'status' => 'failed',
            'attempts' => 1,
            'max_attempts' => 3,
        ]);

        $this->actingAs($this->bareSuperAdmin())->post("/webhooks/deliveries/{$delivery->id}/retry")->assertRedirect();
        $this->assertSame('pending', $delivery->fresh()->status);
    }

    // ─────────── 5. analytics.access + provisioning.access (financial reads) ───────────

    public function test_analytics_403_without_permission(): void
    {
        $user = $this->userWith(['companies.access']); // lacks analytics.access
        $this->actingAs($user)->get('/analytics')->assertForbidden();
    }

    public function test_analytics_ok_with_permission(): void
    {
        $staff = User::factory()->create(); // staff holds analytics.access
        $this->actingAs($staff)->get('/analytics')->assertOk();
    }

    public function test_provisioning_and_subscriptions_403_without_permission(): void
    {
        $user = $this->userWith(['companies.access']); // lacks provisioning.access
        $this->actingAs($user)->get('/provisioning')->assertForbidden();
        $this->actingAs($user)->get('/subscriptions')->assertForbidden();
    }

    public function test_provisioning_and_subscriptions_ok_with_permission(): void
    {
        $staff = User::factory()->create(); // staff holds provisioning.access
        $this->actingAs($staff)->get('/provisioning')->assertOk();
        $this->actingAs($staff)->get('/subscriptions')->assertOk();
    }

    public function test_financial_reads_superadmin_bypass(): void
    {
        $admin = $this->bareSuperAdmin();
        $this->actingAs($admin)->get('/analytics')->assertOk();
        $this->actingAs($admin)->get('/provisioning')->assertOk();
        $this->actingAs($admin)->get('/subscriptions')->assertOk();
    }

    // ─────────── 2b. expenses.approve — the store() create-as-approved side-door ───────────

    public function test_expense_store_as_approved_403_without_approve(): void
    {
        $user = $this->userWith(['companies.access']); // can create expenses, lacks expenses.approve
        $this->actingAs($user)
            ->post('/expenses', $this->expensePayload(['status' => 'approved', 'description' => 'Sneak approved']))
            ->assertForbidden();
        $this->assertDatabaseMissing('expenses', ['description' => 'Sneak approved']);
    }

    public function test_expense_store_as_pending_allowed_without_approve(): void
    {
        // Creating a normal (pending) expense now requires expenses.manage (sprint
        // step 2) but NOT expenses.approve (status pending).
        $user = $this->userWith(['companies.access', 'expenses.manage']);
        $this->actingAs($user)
            ->post('/expenses', $this->expensePayload(['status' => 'pending', 'description' => 'Normal expense']))
            ->assertRedirect();
        $this->assertDatabaseHas('expenses', ['description' => 'Normal expense', 'status' => 'pending']);
    }

    public function test_expense_store_as_approved_succeeds_with_approve(): void
    {
        $user = $this->userWith(['companies.access', 'expenses.manage', 'expenses.approve']);
        $this->actingAs($user)
            ->post('/expenses', $this->expensePayload(['status' => 'approved', 'description' => 'Legit approved']))
            ->assertRedirect();
        $this->assertDatabaseHas('expenses', ['description' => 'Legit approved', 'status' => 'approved']);
    }

    // ─────────── 3b. invoices.manage — the milestone-completion invoice side-door ───────────

    public function test_milestone_completion_creating_invoice_403_without_invoices_manage(): void
    {
        // projects scope All + projects.manage, but NOT invoices.manage.
        $user = $this->projectScopedUser(['projects.manage']);
        $milestone = $this->milestoneWithBillingItem();

        $this->actingAs($user)
            ->put("/milestones/{$milestone->id}", ['title' => 'M1', 'status' => 'completed'])
            ->assertForbidden();

        $this->assertSame('pending', $milestone->fresh()->status); // not completed → no invoice spawned
    }

    public function test_milestone_completion_without_billing_items_allowed(): void
    {
        // Precision: completing a milestone with NO billing items needs only
        // projects access, never invoices.manage — the gate must not over-block.
        $user = $this->projectScopedUser(['projects.manage']);
        $customer = Company::create(['name' => 'NoBill '.uniqid()]);
        $project = Project::create(['title' => 'P', 'customer_id' => $customer->id, 'created_by' => User::factory()->create()->id]);
        $milestone = Milestone::create(['project_id' => $project->id, 'title' => 'M', 'status' => 'pending']);

        $this->actingAs($user)
            ->put("/milestones/{$milestone->id}", ['title' => 'M', 'status' => 'completed'])
            ->assertRedirect();

        $this->assertSame('completed', $milestone->fresh()->status);
    }

    public function test_milestone_completion_creating_invoice_succeeds_with_invoices_manage(): void
    {
        $user = $this->projectScopedUser(['projects.manage', 'invoices.manage']);
        $milestone = $this->milestoneWithBillingItem();

        // Past the invoices.manage gate → milestone completes (the gate does not
        // over-block a permitted user). The downstream invoice generation is the
        // pre-existing triggerMilestoneItems path, not what this gate test covers.
        $this->actingAs($user)
            ->put("/milestones/{$milestone->id}", ['title' => 'M1', 'status' => 'completed'])
            ->assertRedirect();

        $this->assertSame('completed', $milestone->fresh()->status);
    }

    /** Staff-enum user with projects scope = All plus the given permissions (+ companies.access). */
    private function projectScopedUser(array $permissions): User
    {
        $role = Role::create(['name' => 'proj_'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo(array_merge(['companies.access'], $permissions));
        RoleScope::create(['role_id' => $role->id, 'area' => 'projects', 'scope' => 'all']);
        $user = User::factory()->create();
        $user->syncRoles([$role->name]);

        return $user->fresh();
    }

    /** A milestone whose completion WILL spawn an invoice (a pending on-milestone payment item). */
    private function milestoneWithBillingItem(): Milestone
    {
        $owner = User::factory()->create();
        $customer = Company::create(['name' => 'Acme '.uniqid()]);
        $entity = BillingEntity::create([
            'name' => 'Entity '.uniqid(), 'legal_name' => 'Entity Ltd', 'is_active' => true,
            'postmark_sender_email' => 'billing@example.com', 'postmark_sender_name' => 'Billing',
        ]);
        $project = Project::create(['title' => 'Proj '.uniqid(), 'customer_id' => $customer->id, 'created_by' => $owner->id]);
        $milestone = Milestone::create(['project_id' => $project->id, 'title' => 'M1', 'status' => 'pending']);
        $schedule = PaymentSchedule::create([
            'name' => 'Sched', 'customer_id' => $customer->id, 'billing_entity_id' => $entity->id,
            'total' => 100, 'created_by' => $owner->id,
        ]);
        PaymentScheduleItem::create([
            'schedule_id' => $schedule->id, 'label' => 'Phase 1', 'amount' => 100,
            'trigger_type' => 'on_milestone', 'milestone_id' => $milestone->id, 'status' => 'pending', 'sort_order' => 0,
        ]);

        return $milestone;
    }
}
