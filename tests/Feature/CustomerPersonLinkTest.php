<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Layer 1: customer creation links the main contact to a Person, deduping
 * humans by email instead of minting an unlinked duplicate. Purely additive
 * — the primary Contact is still created exactly as before.
 */
class CustomerPersonLinkTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Co',
            'type' => 'restaurant',
            'address_line1' => '1 High St',
            'city' => 'London',
            'postcode' => 'EC1A 1AA',
            'contact_name' => 'Pat Owner',
            'contact_email' => 'pat@gmail.com',
            'contact_role' => 'owner',
        ], $overrides);
    }

    public function test_create_with_picked_person_links_and_creates_no_new_person(): void
    {
        $staff = $this->staff();
        $person = Person::factory()->create(['email' => 'picked@gmail.com', 'name' => 'Picked Person']);
        $before = Person::count();

        $this->actingAs($staff)->post('/companies', $this->payload([
            'person_id' => $person->id,
            'contact_email' => 'whatever@gmail.com', // different email — explicit pick wins
        ]));

        // No new Person minted.
        $this->assertSame($before, Person::count());

        $customer = Company::where('name', 'Acme Co')->firstOrFail();
        $this->assertDatabaseHas('contacts', [
            'customer_id' => $customer->id,
            'is_primary' => true,
            'person_id' => $person->id,
        ]);
        $this->assertDatabaseHas('customer_person', [
            'customer_id' => $customer->id,
            'person_id' => $person->id,
            'role' => 'owner',
        ]);
    }

    public function test_create_with_new_email_auto_creates_and_links_a_person(): void
    {
        $staff = $this->staff();
        $before = Person::count();

        $resp = $this->actingAs($staff)->post('/companies', $this->payload([
            'contact_email' => 'brandnew@gmail.com',
            'contact_name' => 'Brand New',
        ]));
        $resp->assertSessionHasNoErrors();
        $resp->assertStatus(302);

        // A Person was created from the contact.
        $this->assertSame($before + 1, Person::count());
        $person = Person::where('email', 'brandnew@gmail.com')->firstOrFail();
        $this->assertSame('Brand New', $person->name);

        $customer = Company::where('name', 'Acme Co')->firstOrFail();
        $this->assertDatabaseHas('contacts', [
            'customer_id' => $customer->id,
            'is_primary' => true,
            'person_id' => $person->id,
        ]);
        $this->assertDatabaseHas('customer_person', [
            'customer_id' => $customer->id,
            'person_id' => $person->id,
        ]);
    }

    public function test_create_with_matching_email_links_existing_person_no_duplicate(): void
    {
        $staff = $this->staff();
        $existing = Person::factory()->create(['email' => 'dupe@gmail.com', 'name' => 'Existing Human']);
        $before = Person::count();

        $this->actingAs($staff)->post('/companies', $this->payload([
            'contact_email' => 'dupe@gmail.com', // matches existing Person — no person_id sent
            'contact_name' => 'Existing Human (re-typed)',
        ]));

        // Deduped: no new Person for that email.
        $this->assertSame($before, Person::count());
        $this->assertSame(1, Person::where('email', 'dupe@gmail.com')->count());

        $customer = Company::where('name', 'Acme Co')->firstOrFail();
        $this->assertDatabaseHas('contacts', [
            'customer_id' => $customer->id,
            'is_primary' => true,
            'person_id' => $existing->id,
        ]);
        $this->assertDatabaseHas('customer_person', [
            'customer_id' => $customer->id,
            'person_id' => $existing->id,
        ]);
    }
}
