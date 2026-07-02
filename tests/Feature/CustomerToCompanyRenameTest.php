<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer -> Company rename (2026-07): the model/UI/route rename keeps the
 * `customers` table and every `customer_id` FK untouched. These guard the two
 * things the rename could silently break:
 *   1. The renamed model must still read/write `customers` ($table pin) and
 *      infer `customer_id` for convention relations (getForeignKey override).
 *   2. Links already in the wild (/customers*) must 301 to the new /companies*.
 */
class CustomerToCompanyRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_model_round_trips_against_the_customers_table(): void
    {
        $company = Company::create(['name' => 'Acme Co', 'pipeline_stage' => 'active']);

        // The $table pin: without it Eloquent would target a non-existent
        // `companies` table and every read/write would throw.
        $this->assertDatabaseHas('customers', ['id' => $company->id, 'name' => 'Acme Co']);
        $this->assertSame('customers', $company->getTable());

        $company->update(['name' => 'Acme Renamed']);
        $this->assertDatabaseHas('customers', ['id' => $company->id, 'name' => 'Acme Renamed']);

        $this->assertTrue(Company::whereKey($company->id)->exists());
    }

    public function test_convention_relations_still_infer_customer_id(): void
    {
        $company = Company::create(['name' => 'Acme Co', 'pipeline_stage' => 'active']);
        $contact = Contact::create([
            'customer_id' => $company->id,
            'name' => 'Pat Smith',
            'email' => 'pat@acme.test',
            'role' => 'owner',
            'is_primary' => true,
        ]);

        // getForeignKey() returns 'customer_id', so hasMany resolves against the
        // kept FK column rather than the convention-derived 'company_id'.
        $this->assertSame('customer_id', $company->getForeignKey());
        $this->assertTrue($company->contacts->contains($contact));
        $this->assertTrue($company->primaryContact->is($contact));
    }

    public function test_legacy_customers_index_url_redirects_to_companies(): void
    {
        $this->get('/customers')->assertRedirect('/companies');
    }

    public function test_legacy_customer_detail_url_redirects_preserving_the_path(): void
    {
        // The catch-all preserves everything after /customers/, so a deep-linked
        // company page (and any sub-path) lands on its /companies equivalent.
        $this->get('/customers/42')->assertRedirect('/companies/42');
        $this->get('/customers/42/products')->assertRedirect('/companies/42/products');
    }

    public function test_kept_compound_url_is_not_swallowed_by_the_redirect(): void
    {
        // /customer-groups shares the "customer" prefix but is NOT /customers/*,
        // so the redirect catch-all must never intercept it: it reaches its real
        // auth-gated route (302 to login), never a 301 to a /companies path.
        $this->get('/customer-groups')->assertRedirect('/login');
    }

    public function test_renamed_route_names_resolve_to_companies_paths(): void
    {
        $this->assertSame(url('/companies'), route('internal.companies.index'));
        $this->assertSame(url('/companies/7'), route('internal.companies.show', 7));
    }

    public function test_super_admin_reaches_the_companies_index(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->get('/companies')->assertOk();
    }
}
