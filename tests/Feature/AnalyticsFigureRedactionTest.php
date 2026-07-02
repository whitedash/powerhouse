<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section-enforcement sprint, step 11 — analytics financial-figure redaction.
 *
 * Unlike steps 1-10 (route gates), this is FIELD-LEVEL redaction: MRR/ARR/
 * pending-£/commission-£/per-customer-price figures are nulled server-side when
 * the user lacks analytics.access, while the home-reachable pages (/dashboard,
 * /products/{slug}) and the CSV stay reachable. The key assertion is "page still
 * loads (200) AND the money figures are gone" — proving redaction, not blocking.
 *
 * super_admin holds analytics.access via Gate::before → sees all figures.
 */
class AnalyticsFigureRedactionTest extends TestCase
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
        $role = Role::create(['name' => 'an_'.uniqid(), 'guard_name' => 'web']);
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

    private function makeProduct(): Product
    {
        return Product::create([
            'name' => 'Test Product '.uniqid(),
            'slug' => 'test-'.uniqid(),
            'is_active' => true,
        ]);
    }

    // ─── /dashboard — page loads, money figures redacted ───

    public function test_dashboard_loads_but_redacts_financials_without_analytics_access(): void
    {
        // companies.access reaches the dashboard; NO analytics.access → money gone.
        $user = $this->userWith(['companies.access']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk() // page STILL renders
            ->assertInertia(fn (Assert $p) => $p
                ->where('stats.mrr', null)
                ->where('stats.pending_invoices_amount', null)
                ->where('this_month.commissions_due', null)
                ->where('total_pending_commissions', null)
                ->whereNot('stats.total_customers', null)); // non-financial stat remains
    }

    public function test_dashboard_shows_financials_with_analytics_access(): void
    {
        $user = $this->userWith(['companies.access', 'analytics.access']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->whereNot('stats.mrr', null)
                ->whereNot('total_pending_commissions', null));
    }

    public function test_dashboard_superadmin_sees_financials(): void
    {
        $this->actingAs($this->bareSuperAdmin())->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->whereNot('stats.mrr', null));
    }

    // ─── /export/dashboard CSV — money columns redacted, ops counts remain ───

    public function test_export_csv_redacts_money_columns_without_analytics_access(): void
    {
        $user = $this->userWith(['companies.access']);
        $res = $this->actingAs($user)->get('/export/dashboard');
        $res->assertOk();
        $csv = $res->streamedContent();

        // money rows/columns gone...
        $this->assertStringNotContainsString('MRR (£)', $csv);
        $this->assertStringNotContainsString('ARR (£)', $csv);
        $this->assertStringNotContainsString('Pending invoices (£)', $csv);
        $this->assertStringNotContainsString('Revenue collected', $csv);
        // ...but the non-financial ops snapshot remains.
        $this->assertStringContainsString('Total customers', $csv);
        $this->assertStringContainsString('Open tickets', $csv);
    }

    public function test_export_csv_includes_money_columns_with_analytics_access(): void
    {
        $user = $this->userWith(['companies.access', 'analytics.access']);
        $csv = $this->actingAs($user)->get('/export/dashboard')->streamedContent();

        $this->assertStringContainsString('MRR (£)', $csv);
        $this->assertStringContainsString('ARR (£)', $csv);
    }

    // ─── /products/{slug} — base gate (provisioning.access) + figure redaction ───

    public function test_product_page_403_without_provisioning_access(): void
    {
        $product = $this->makeProduct();
        // analytics.access but NOT provisioning.access → can't even reach the page.
        $user = $this->userWith(['analytics.access']);
        $this->actingAs($user)->get("/products/{$product->slug}")->assertForbidden();
    }

    public function test_product_page_loads_but_redacts_financials_without_analytics_access(): void
    {
        $product = $this->makeProduct();
        // provisioning.access reaches the page; NO analytics.access → KPIs redacted.
        $user = $this->userWith(['provisioning.access']);

        $this->actingAs($user)->get("/products/{$product->slug}")
            ->assertOk() // page STILL renders
            ->assertInertia(fn (Assert $p) => $p
                ->where('kpis.mrr', null)
                ->where('kpis.arr', null)
                ->whereNot('kpis.active_customers', null)); // non-financial KPI remains
    }

    public function test_product_page_shows_financials_with_analytics_access(): void
    {
        $product = $this->makeProduct();
        $user = $this->userWith(['provisioning.access', 'analytics.access']);

        $this->actingAs($user)->get("/products/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->whereNot('kpis.mrr', null));
    }

    public function test_product_page_superadmin_sees_financials(): void
    {
        $product = $this->makeProduct();
        $this->actingAs($this->bareSuperAdmin())->get("/products/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->whereNot('kpis.mrr', null));
    }

    // ─── /subscriptions — aggregate MRR/ARR redacted (folded in via adversarial) ───

    public function test_subscriptions_analytics_redacted_without_analytics_access(): void
    {
        // provisioning.access reaches the page; NO analytics.access → MRR/ARR gone.
        $user = $this->userWith(['provisioning.access']);

        $this->actingAs($user)->get('/subscriptions')
            ->assertOk() // page STILL renders (subscription management stays usable)
            ->assertInertia(fn (Assert $p) => $p
                ->where('analytics.mrr', null)
                ->where('analytics.arr', null)
                ->whereNot('analytics.active_count', null)); // non-financial stays
    }

    public function test_subscriptions_analytics_shown_with_analytics_access(): void
    {
        $user = $this->userWith(['provisioning.access', 'analytics.access']);
        $this->actingAs($user)->get('/subscriptions')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->whereNot('analytics.mrr', null));
    }

    // ─── /companies list + show — per-customer MRR redacted (folded in) ───

    public function test_customers_list_redacts_per_customer_mrr_without_analytics_access(): void
    {
        Company::create(['name' => 'Acme '.uniqid()]);
        $user = $this->userWith(['companies.access']);

        $this->actingAs($user)->get('/companies')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('customers.data.0.mrr', null));
    }

    public function test_customers_list_shows_per_customer_mrr_with_analytics_access(): void
    {
        Company::create(['name' => 'Acme '.uniqid()]);
        $user = $this->userWith(['companies.access', 'analytics.access']);

        $this->actingAs($user)->get('/companies')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->whereNot('customers.data.0.mrr', null));
    }

    public function test_customer_show_redacts_mrr_without_analytics_access(): void
    {
        $customer = Company::create(['name' => 'Acme '.uniqid()]);
        $user = $this->userWith(['companies.access']);

        $this->actingAs($user)->get("/companies/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('customer.mrr', null));
    }

    public function test_customer_show_shows_mrr_with_analytics_access(): void
    {
        $customer = Company::create(['name' => 'Acme '.uniqid()]);
        $user = $this->userWith(['companies.access', 'analytics.access']);

        $this->actingAs($user)->get("/companies/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->whereNot('customer.mrr', null));
    }

    // ─── /settings/products/{id}/plans — per-plan aggregate MRR (6th surface) ───

    public function test_product_plans_builder_redacts_per_plan_mrr_without_analytics_access(): void
    {
        $product = $this->makeProduct();
        ProductPlan::create(['product_id' => $product->id, 'name' => 'Plan '.uniqid()]);
        // products.manage reaches the plan-builder; NO analytics.access → MRR gone.
        $user = $this->userWith(['products.manage']);

        $this->actingAs($user)->get("/settings/products/{$product->id}/plans")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('uncategorised.0.mrr', null));
    }

    public function test_product_plans_builder_shows_per_plan_mrr_with_analytics_access(): void
    {
        $product = $this->makeProduct();
        ProductPlan::create(['product_id' => $product->id, 'name' => 'Plan '.uniqid()]);
        $user = $this->userWith(['products.manage', 'analytics.access']);

        $this->actingAs($user)->get("/settings/products/{$product->id}/plans")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->whereNot('uncategorised.0.mrr', null));
    }
}
