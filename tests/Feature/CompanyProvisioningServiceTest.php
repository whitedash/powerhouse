<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use App\Services\CompanyProvisioningService;
use App\Services\CompanyProvisionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CompanyProvisioningService — the Company + primary Contact + people-layer
 * assembly extracted from CompanyController::store / LeadController::convert
 * (Plans-widget foundation, PLANS-WIDGET-DESIGN.md §2). The controller-level
 * behaviour is locked by PersonDedupAtomicityTest (store + convert still
 * route through the same funnel); this suite exercises the service directly,
 * in particular the null-actor "system" path that no controller uses yet.
 *
 * Emails use @gmail.com for the same reason as PersonDedupAtomicityTest:
 * contact emails elsewhere validate email:rfc,dns, and consistency beats
 * a second convention.
 */
class CompanyProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array{name: string, email: string|null, phone?: string|null, job_title?: string|null, role?: string|null, person_id?: int|null}|null  $contact
     */
    private function provision(?array $contact, ?User $actor): CompanyProvisionResult
    {
        $service = app(CompanyProvisioningService::class);

        // Callers own the transaction boundary (both controllers wrap the
        // service in DB::transaction) — mirror that here.
        return DB::transaction(fn (): CompanyProvisionResult => $service->provision([
            'name' => 'Acme Co',
            'type' => 'restaurant',
            'address_line1' => '1 High St',
            'city' => 'London',
            'postcode' => 'EC1A 1AA',
            'country' => 'GB',
            'pipeline_stage' => 'lead',
        ], $contact, $actor));
    }

    public function test_provision_with_actor_creates_company_contact_person_and_attributed_logs(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin']);

        $result = $this->provision([
            'name' => 'Pat Owner',
            'email' => 'pat.new@gmail.com',
            'role' => 'owner',
        ], $actor);

        $this->assertDatabaseHas('customers', ['id' => $result->company->id, 'name' => 'Acme Co']);
        $this->assertDatabaseHas('contacts', [
            'customer_id' => $result->company->id,
            'person_id' => $result->person?->id,
            'is_primary' => true,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('customer_person', [
            'customer_id' => $result->company->id,
            'person_id' => $result->person?->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'action' => 'person.created',
            'entity_id' => $result->person?->id,
            'user_id' => $actor->id,
            'user_role' => 'super_admin',
        ]);
    }

    public function test_provision_with_null_actor_records_system_activity_rows(): void
    {
        $result = $this->provision([
            'name' => 'Pat Owner',
            'email' => 'pat.system@gmail.com',
        ], null);

        $this->assertNotNull($result->person);
        $this->assertNull($result->person->created_by);

        // Same convention as StripeService::markInvoicePaid — the audit
        // trail must show a system actor, not a missing one.
        foreach (['person.created', 'person.company_attached'] as $action) {
            $this->assertDatabaseHas('activity_log', [
                'action' => $action,
                'entity_id' => $result->person->id,
                'user_id' => null,
                'user_role' => 'system',
            ]);
        }
    }

    public function test_provision_with_null_actor_still_dedupes_person_by_email(): void
    {
        $existing = Person::factory()->create(['email' => 'pat.existing@gmail.com', 'name' => 'Existing Pat']);

        $result = $this->provision([
            'name' => 'Pat Owner',
            'email' => 'pat.existing@gmail.com',
        ], null);

        $this->assertNotNull($result->person);
        $this->assertTrue($result->person->is($existing));
        $this->assertSame(1, Person::count());
        $this->assertDatabaseHas('customer_person', [
            'customer_id' => $result->company->id,
            'person_id' => $existing->id,
        ]);
    }

    public function test_provision_without_contact_creates_a_bare_company(): void
    {
        $result = $this->provision(null, null);

        $this->assertNull($result->contact);
        $this->assertNull($result->person);
        $this->assertDatabaseHas('customers', ['id' => $result->company->id]);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('people', 0);
    }
}
