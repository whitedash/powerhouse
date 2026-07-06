<?php

namespace App\Services;

use App\Enums\PersonRole;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * All write operations for the people↔companies layer. Controllers stay
 * thin: they authorise + validate, then delegate here. Every mutation
 * runs in a transaction and is recorded to activity_log.
 *
 * Authorisation is the controller's job (policy + FormRequest); this
 * service assumes the caller is already allowed to act.
 *
 * The creation/link funnel (create, createOrLinkFromContact,
 * attachCompany) accepts a null $actor for unauthenticated system paths
 * (e.g. webhook-driven provisioning — PLANS-WIDGET-DESIGN.md §2); null is
 * recorded as user_id=null / user_role='system', the same convention as
 * StripeService::markInvoicePaid(). Operator-only mutations (update,
 * delete, detachCompany, setRole) still require a real User.
 */
class PersonService
{
    /**
     * @param  array{name: string, email?: string|null, phone?: string|null, notes?: string|null}  $data
     */
    public function create(array $data, ?User $actor): Person
    {
        return DB::transaction(function () use ($data, $actor): Person {
            $person = Person::create([
                ...$data,
                'created_by' => $actor?->id,
            ]);

            $this->log($actor, 'person.created', $person->id, after: [
                'name' => $person->name,
                'email' => $person->email,
            ]);

            return $person;
        });
    }

    /**
     * @param  array{name: string, email?: string|null, phone?: string|null, notes?: string|null}  $data
     */
    public function update(Person $person, array $data, User $actor): Person
    {
        return DB::transaction(function () use ($person, $data, $actor): Person {
            $before = $person->only(['name', 'email', 'phone', 'notes']);

            $person->update($data);

            $this->log($actor, 'person.updated', $person->id,
                before: $before,
                after: $person->only(['name', 'email', 'phone', 'notes']),
            );

            return $person;
        });
    }

    public function delete(Person $person, User $actor): void
    {
        DB::transaction(function () use ($person, $actor): void {
            $snapshot = ['name' => $person->name, 'email' => $person->email];

            // Detach company associations explicitly so the pivot rows go
            // even though the FK cascades — keeps intent clear in the log.
            // contacts.person_id is nullOnDelete, so linked contacts simply
            // lose the link rather than disappearing.
            $person->companies()->detach();
            $person->delete();

            $this->log($actor, 'person.deleted', $person->id, before: $snapshot);
        });
    }

    /**
     * Resolve the Person for a newly-created contact, deduping humans by
     * email so customer creation doesn't mint an unlinked duplicate:
     *   1. explicit $personId (operator picked an existing Person) → use it;
     *   2. else a Person with the same email already exists → reuse it;
     *   3. else create a new Person from the contact's name/email.
     * The caller links the contact (contacts.person_id) and the company
     * (attachCompany) — this method only owns Person resolution.
     */
    public function createOrLinkFromContact(?int $personId, string $name, ?string $email, ?User $actor): Person
    {
        if ($personId !== null) {
            $existing = Person::find($personId);
            if ($existing !== null) {
                return $existing;
            }
        }

        // Trim-normalise before matching, mirroring ContactMatchService: the
        // utf8mb4_unicode_ci collation already folds case/accents, but leading
        // or trailing whitespace would otherwise miss an existing Person.
        $email = trim((string) $email);
        if ($email === '') {
            // No email to dedupe on — an email-less person is always fresh.
            return $this->create(['name' => $name, 'email' => null], $actor);
        }

        $byEmail = Person::where('email', $email)->first();
        if ($byEmail !== null) {
            return $byEmail;
        }

        // No match at check time — but a concurrent customer-create for the
        // same new email can insert the Person between this check and ours.
        // The people.email UNIQUE index then rejects our insert; recover by
        // re-reading and reusing the winner rather than letting the exception
        // propagate (previously swallowed by CompanyController's after-commit
        // catch, which silently left contacts.person_id null).
        try {
            return $this->create(['name' => $name, 'email' => $email], $actor);
        } catch (QueryException $e) {
            // lockForUpdate forces a current read: the winner committed after
            // our transaction's snapshot, so a plain SELECT could miss it.
            $winner = Person::where('email', $email)->lockForUpdate()->first();
            if ($winner !== null) {
                return $winner;
            }

            throw $e; // a different constraint — do not swallow it
        }
    }

    /**
     * Associate a person with a company (or update the association's role /
     * job_title if it already exists — idempotent on the unique pair).
     */
    public function attachCompany(
        Person $person,
        Company $customer,
        PersonRole $role,
        ?string $jobTitle,
        ?User $actor,
    ): void {
        DB::transaction(function () use ($person, $customer, $role, $jobTitle, $actor): void {
            // syncWithoutDetaching is idempotent: re-attaching the same
            // company updates the pivot attributes instead of erroring on
            // the (customer_id, person_id) unique constraint.
            $person->companies()->syncWithoutDetaching([
                $customer->id => [
                    'role' => $role->value,
                    'job_title' => $jobTitle,
                ],
            ]);

            $this->log($actor, 'person.company_attached', $person->id, after: [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'role' => $role->value,
            ]);
        });
    }

    public function detachCompany(Person $person, Company $customer, User $actor): void
    {
        DB::transaction(function () use ($person, $customer, $actor): void {
            $person->companies()->detach($customer->id);

            $this->log($actor, 'person.company_detached', $person->id, after: [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
            ]);
        });
    }

    /**
     * Change the role (and optionally job_title) on an existing
     * association without touching other associations.
     */
    public function setRole(
        Person $person,
        Company $customer,
        PersonRole $role,
        ?string $jobTitle,
        User $actor,
    ): void {
        DB::transaction(function () use ($person, $customer, $role, $jobTitle, $actor): void {
            $person->companies()->updateExistingPivot($customer->id, [
                'role' => $role->value,
                'job_title' => $jobTitle,
            ]);

            $this->log($actor, 'person.role_changed', $person->id, after: [
                'customer_id' => $customer->id,
                'role' => $role->value,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(?User $actor, string $action, int $personId, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $actor?->id,
            'user_role' => $actor->role ?? 'system',
            'action' => $action,
            'entity_type' => Person::class,
            'entity_id' => $personId,
            'before' => $before,
            'after' => $after,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);
    }
}
