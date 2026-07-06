<?php

namespace App\Services;

use App\Enums\PersonRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;

/**
 * Assembles a Company + primary Contact + the people-layer identity link
 * (Person dedupe + customer_person pivot) — the shape shared by internal
 * customer creation (CompanyController::store) and lead conversion
 * (LeadController::convert), extracted so a future system-actor path
 * (Plans widget webhook provisioning — PLANS-WIDGET-DESIGN.md §2) can
 * build the same records without a controller.
 *
 * Deliberately NOT transactional: both existing callers wrap wider work
 * (referral attribution, lead migration + stamping) around this assembly,
 * so the caller owns the DB::transaction boundary. Authorisation is
 * likewise the caller's job. A null $actor means "system" and is recorded
 * as user_id=null / user_role='system' on the people-layer activity_log
 * rows (see PersonService).
 */
class CompanyProvisioningService
{
    public function __construct(private readonly PersonService $people) {}

    /**
     * $companyAttributes are validated attributes handed straight to
     * Company::create — field mapping (channel coercion, pipeline-stage
     * defaults) stays at the call site. $contact is the primary-contact
     * data, or null to create the company without one (a contact needs at
     * least some way to reach the human).
     *
     * @param  array<string, mixed>  $companyAttributes
     * @param  array{name: string, email: string|null, phone?: string|null, job_title?: string|null, role?: string|null, person_id?: int|null}|null  $contact
     */
    public function provision(array $companyAttributes, ?array $contact, ?User $actor): CompanyProvisionResult
    {
        $company = Company::create($companyAttributes);

        if ($contact === null) {
            return new CompanyProvisionResult($company, null, null);
        }

        $role = $contact['role'] ?? 'owner';

        $contactRow = Contact::create([
            'customer_id' => $company->id,
            'name' => $contact['name'],
            'email' => $contact['email'],
            'phone' => $contact['phone'] ?? null,
            'job_title' => $contact['job_title'] ?? null,
            'role' => $role,
            'is_primary' => true,
        ]);

        // People-layer link (Layer 1): dedupe the human by email and tie
        // the primary contact to a Person + a customer_person pivot row.
        // Runs inside the caller's transaction: the Person resolve recovers
        // from the people.email UNIQUE race internally (see
        // PersonService::createOrLinkFromContact), so a throw here is a
        // genuine error that SHOULD roll the whole create back rather than
        // being silently swallowed into an unlinked contact. Comms still
        // read customer->primaryContact.
        $person = $this->people->createOrLinkFromContact(
            $contact['person_id'] ?? null,
            $contact['name'],
            $contact['email'],
            $actor,
        );
        $contactRow->update(['person_id' => $person->id]);

        // Contact roles are a superset of PersonRole (single source of
        // truth for the pivot), so coerce unknowns onto Owner — the
        // primary contact minted at creation time is the owner in practice.
        $this->people->attachCompany(
            $person,
            $company,
            PersonRole::tryFrom($role) ?? PersonRole::Owner,
            $contactRow->job_title,
            $actor,
        );

        return new CompanyProvisionResult($company, $contactRow, $person);
    }
}
