<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Person-dedup atomicity fix. createOrLinkFromContact now trims + reuses an
 * existing Person by email, and recovers from the people.email UNIQUE race
 * (previously a swallowed exception that left contacts.person_id null). The
 * funnel runs inside CompanyController::store's transaction, and
 * LeadController::convert now routes its primary contact through the same
 * funnel instead of minting an orphaned Contact.
 *
 * Emails use @gmail.com because StoreCompanyRequest validates contact_email
 * with email:rfc,dns (a live DNS lookup) — .test/.example domains fail it.
 */
class PersonDedupAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /** @return array<string, mixed> */
    private function customerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Co', 'type' => 'restaurant',
            'address_line1' => '1 High St', 'city' => 'London', 'postcode' => 'EC1A 1AA',
            'contact_name' => 'Pat Owner', 'contact_email' => 'pat.new@gmail.com', 'contact_role' => 'owner',
        ], $overrides);
    }

    // ── store() ──────────────────────────────────────────────────────────

    public function test_store_with_a_brand_new_email_creates_a_person(): void
    {
        $this->actingAs($this->staff())->post('/companies', $this->customerPayload())->assertRedirect();

        $person = Person::where('email', 'pat.new@gmail.com')->firstOrFail();
        $customer = Company::where('name', 'Acme Co')->firstOrFail();
        $this->assertDatabaseHas('contacts', ['customer_id' => $customer->id, 'is_primary' => true, 'person_id' => $person->id]);
        $this->assertDatabaseHas('customer_person', ['customer_id' => $customer->id, 'person_id' => $person->id, 'role' => 'owner']);
    }

    public function test_store_with_an_existing_email_reuses_the_person(): void
    {
        $existing = Person::factory()->create(['email' => 'pat.existing@gmail.com', 'name' => 'Existing Pat']);
        $before = Person::count();

        $this->actingAs($this->staff())
            ->post('/companies', $this->customerPayload(['contact_email' => 'pat.existing@gmail.com']))
            ->assertRedirect();

        // No new Person minted; the contact + pivot point at the existing one.
        $this->assertSame($before, Person::count());
        $customer = Company::where('name', 'Acme Co')->firstOrFail();
        $this->assertDatabaseHas('contacts', ['customer_id' => $customer->id, 'person_id' => $existing->id]);
        $this->assertDatabaseHas('customer_person', ['customer_id' => $customer->id, 'person_id' => $existing->id]);
    }

    public function test_store_reuses_a_person_whose_email_differs_only_by_surrounding_whitespace(): void
    {
        // The trim() normalization: a padded contact_email must still resolve
        // to the existing Person, not mint a duplicate.
        $existing = Person::factory()->create(['email' => 'pat.pad@gmail.com']);
        $before = Person::count();

        $this->actingAs($this->staff())
            ->post('/companies', $this->customerPayload(['contact_email' => '  pat.pad@gmail.com  ']))
            ->assertRedirect();

        $this->assertSame($before, Person::count());
        $customer = Company::where('name', 'Acme Co')->firstOrFail();
        $this->assertDatabaseHas('contacts', ['customer_id' => $customer->id, 'person_id' => $existing->id]);
    }

    // (The concurrency-recovery case lives in PersonDedupRaceTest — it needs a
    //  real committed row on a second connection, which RefreshDatabase's
    //  wrapping transaction would lock against.)

    // ── convert() ────────────────────────────────────────────────────────

    private function convertibleLead(string $email): Lead
    {
        return Lead::create([
            'first_name' => 'Jo', 'last_name' => 'Prospect', 'email' => $email,
            'status' => 'qualified', 'created_by' => $this->staff()->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function convertPayload(): array
    {
        return [
            'name' => 'Converted Co', 'type' => 'restaurant',
            'address_line1' => '2 Market St', 'city' => 'Leeds', 'postcode' => 'LS1 1AA',
        ];
    }

    public function test_convert_links_the_contact_to_an_existing_person_by_email(): void
    {
        $existing = Person::factory()->create(['email' => 'jo.existing@gmail.com', 'name' => 'Existing Jo']);
        $lead = $this->convertibleLead('jo.existing@gmail.com');
        $before = Person::count();

        $this->actingAs($this->staff())->post("/leads/{$lead->id}/convert", $this->convertPayload())->assertRedirect();

        // No new Person; the converted lead's contact links to the existing one.
        $this->assertSame($before, Person::count());
        $customer = Company::where('name', 'Converted Co')->firstOrFail();
        $this->assertDatabaseHas('contacts', ['customer_id' => $customer->id, 'is_primary' => true, 'person_id' => $existing->id]);
        $this->assertDatabaseHas('customer_person', ['customer_id' => $customer->id, 'person_id' => $existing->id, 'role' => 'owner']);
    }

    public function test_convert_with_a_new_email_creates_and_links_a_person(): void
    {
        $lead = $this->convertibleLead('jo.brandnew@gmail.com');

        $this->actingAs($this->staff())->post("/leads/{$lead->id}/convert", $this->convertPayload())->assertRedirect();

        $person = Person::where('email', 'jo.brandnew@gmail.com')->firstOrFail();
        $customer = Company::where('name', 'Converted Co')->firstOrFail();
        $this->assertDatabaseHas('contacts', ['customer_id' => $customer->id, 'is_primary' => true, 'person_id' => $person->id]);
        $this->assertDatabaseHas('customer_person', ['customer_id' => $customer->id, 'person_id' => $person->id]);
    }
}
