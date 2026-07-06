<?php

namespace Tests\Feature;

use App\Mail\PlanPurchaseReceipt;
use App\Models\BillingEntity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\ProductPlanPrice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plans widget review gate (PLANS-WIDGET-DESIGN.md §4): the confirm action
 * that moves a requires_manual_review purchase from status='pending' to
 * 'active' and sends the receipt withheld at webhook provisioning.
 * Company-scoped route, gated by companies.manage (CompanyPolicy::update),
 * idempotent on the status guard.
 */
class PlanPurchaseReviewConfirmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'confirm_'.uniqid(), 'guard_name' => 'web']);
        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }
        // No RoleScope row: companies aren't a ScopeArea — company actions
        // gate on permissions alone.
        $user = User::factory()->create();
        $user->syncRoles([$role->name]);

        return $user->fresh();
    }

    /**
     * A webhook-provisioned pending purchase: company + primary contact,
     * plan catalog, pending customer_product, and the paid plan invoice
     * whose line carries the plan_id (how confirm() finds the receipt's
     * invoice).
     *
     * @return array{0: Company, 1: CustomerProduct}
     */
    private function pendingPurchase(string $status = 'pending'): array
    {
        $entity = BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
            'is_active' => true,
        ]);
        $company = Company::create(['name' => 'Pat Purchaser']);
        Contact::create([
            'customer_id' => $company->id,
            'name' => 'Pat Purchaser',
            'email' => 'pat.purchaser@gmail.com',
            'role' => 'owner',
            'is_primary' => true,
        ]);

        $product = Product::create(['slug' => 'comnicube', 'name' => 'ComniCube', 'is_active' => true]);
        $plan = ProductPlan::create([
            'product_id' => $product->id, 'name' => 'Starter',
            'is_active' => true, 'is_public' => true, 'requires_manual_review' => true,
        ]);
        $price = ProductPlanPrice::create([
            'plan_id' => $plan->id, 'price' => 100,
            'interval_count' => 1, 'interval_unit' => 'one_time', 'is_active' => true,
        ]);

        $cp = CustomerProduct::create([
            'customer_id' => $company->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'status' => $status,
            'started_at' => now(),
        ]);

        $invoice = Invoice::create([
            'number' => 'INV-7001',
            'customer_id' => $company->id,
            'billing_entity_id' => $entity->id,
            'type' => 'subscription',
            'status' => 'paid',
            'subtotal' => 100, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 100,
            'amount_paid' => 100,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'paid_at' => now(),
            'paid_via' => 'stripe',
            'created_by' => null,
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'description' => 'ComniCube — Starter',
            'quantity' => 1, 'unit_price' => 100, 'amount' => 100,
        ]);

        return [$company, $cp];
    }

    private function confirmUrl(Company $company, CustomerProduct $cp): string
    {
        return "/companies/{$company->id}/customer-products/{$cp->id}/confirm";
    }

    public function test_confirm_with_both_permissions_activates_sends_receipt_and_logs_the_real_actor(): void
    {
        Mail::fake();
        [$company, $cp] = $this->pendingPurchase();
        // Double gate: provisioning.manage (route middleware) AND
        // companies.manage (in-method policy) — the happy path holds both.
        $staff = $this->userWith(['companies.access', 'companies.manage', 'provisioning.manage']);

        $this->actingAs($staff)
            ->post($this->confirmUrl($company, $cp))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('active', $cp->fresh()->status);

        // Real actor this time — an internal user confirmed, not the system.
        $this->assertDatabaseHas('activity_log', [
            'action' => 'customer_product.review_confirmed',
            'entity_type' => 'customer',
            'entity_id' => $company->id,
            'user_id' => $staff->id,
        ]);

        Mail::assertSent(PlanPurchaseReceipt::class, function (PlanPurchaseReceipt $mail): bool {
            return $mail->hasTo('pat.purchaser@gmail.com');
        });
    }

    public function test_confirm_requires_provisioning_manage_at_the_route(): void
    {
        Mail::fake();
        [$company, $cp] = $this->pendingPurchase();
        // companies.manage alone is no longer enough — the route's
        // provisioning.manage section gate 403s before the controller runs.
        $staff = $this->userWith(['companies.access', 'companies.manage']);

        $this->actingAs($staff)
            ->post($this->confirmUrl($company, $cp))
            ->assertForbidden();

        $this->assertSame('pending', $cp->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_confirm_requires_companies_manage_in_method(): void
    {
        Mail::fake();
        [$company, $cp] = $this->pendingPurchase();
        // The gates compose: provisioning.manage clears the middleware,
        // but CompanyPolicy::update (companies.manage) still 403s.
        $provisioner = $this->userWith(['companies.access', 'provisioning.manage']);

        $this->actingAs($provisioner)
            ->post($this->confirmUrl($company, $cp))
            ->assertForbidden();

        $this->assertSame('pending', $cp->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_confirming_an_already_active_row_422s_and_sends_nothing(): void
    {
        Mail::fake();
        [$company, $cp] = $this->pendingPurchase(status: 'active');
        $staff = $this->userWith(['companies.access', 'companies.manage', 'provisioning.manage']);

        $this->actingAs($staff)
            ->postJson($this->confirmUrl($company, $cp))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        Mail::assertNothingSent();
    }

    public function test_show_page_exposes_the_confirm_visibility_prop_by_permission(): void
    {
        // No Vue test runner in this repo — the button's v-if is driven by
        // this server-computed prop, so assert it at the Inertia layer
        // (the established assertInertia pattern).
        [$company] = $this->pendingPurchase();

        $both = $this->userWith(['companies.access', 'companies.manage', 'provisioning.manage']);
        $this->actingAs($both)
            ->get("/companies/{$company->id}")
            ->assertInertia(fn ($page) => $page->where('can_confirm_pending', true));

        $companiesOnly = $this->userWith(['companies.access', 'companies.manage']);
        $this->actingAs($companiesOnly)
            ->get("/companies/{$company->id}")
            ->assertInertia(fn ($page) => $page->where('can_confirm_pending', false));
    }

    public function test_confirming_a_nonexistent_customer_product_404s(): void
    {
        [$company] = $this->pendingPurchase();
        $staff = $this->userWith(['companies.access', 'companies.manage', 'provisioning.manage']);

        $this->actingAs($staff)
            ->post("/companies/{$company->id}/customer-products/999999/confirm")
            ->assertNotFound();
    }

    public function test_confirming_another_companys_subscription_404s(): void
    {
        Mail::fake();
        [, $cp] = $this->pendingPurchase();
        $otherCompany = Company::create(['name' => 'Unrelated Co']);
        $staff = $this->userWith(['companies.access', 'companies.manage', 'provisioning.manage']);

        // IDOR guard: mismatched company/customer-product pair reads as a
        // wrong id, not a hint that the row exists.
        $this->actingAs($staff)
            ->post($this->confirmUrl($otherCompany, $cp))
            ->assertNotFound();

        $this->assertSame('pending', $cp->fresh()->status);
        Mail::assertNothingSent();
    }
}
