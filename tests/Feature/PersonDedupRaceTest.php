<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use App\Services\PersonService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The people.email UNIQUE race-recovery branch of
 * PersonService::createOrLinkFromContact, exercised with GENUINE concurrency:
 * a second DB connection commits the winning Person in the window between the
 * service's email check and its own insert.
 *
 * Deliberately does NOT use RefreshDatabase: the recovery re-reads with
 * lockForUpdate, and that row lock — held for the life of RefreshDatabase's
 * wrapping transaction — would block the cross-connection cleanup. Without the
 * wrapping transaction the lock releases at statement end, so the test manages
 * its own committed rows and cleans them up explicitly.
 */
class PersonDedupRaceTest extends TestCase
{
    public function test_a_concurrent_create_on_the_same_new_email_reuses_the_winner(): void
    {
        config(['database.connections.race' => config('database.connections.mysql')]);
        // Unique per run so a failed cleanup can never collide with a later run.
        $email = 'race.'.uniqid().'@gmail.com';
        $actor = User::factory()->create(['role' => 'super_admin']);

        $injectedId = null;
        Person::creating(function (Person $p) use (&$injectedId, $email): void {
            if ($p->email === $email && $injectedId === null) {
                // A concurrent writer commits the winner between our check and
                // our insert. Raw insert on the independent connection commits
                // immediately and fires no Eloquent events (no recursion);
                // created_by is null so it needs no cross-connection FK lookup.
                $injectedId = DB::connection('race')->table('people')->insertGetId([
                    'name' => 'Winner', 'email' => $email, 'created_by' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        try {
            $result = app(PersonService::class)->createOrLinkFromContact(null, 'Loser', $email, $actor);

            // Recovered: our insert hit people.email UNIQUE, and the service
            // re-read and returned the concurrent winner rather than throwing
            // (which the caller previously swallowed). Exactly one row exists.
            $this->assertSame($injectedId, $result->id, 'the service reused the concurrent winner');
            $this->assertSame('Winner', $result->name);
            $this->assertSame(1, Person::where('email', $email)->count(), 'no duplicate Person row exists');
        } finally {
            Person::flushEventListeners();
            // Real committed rows (no wrapping transaction) — clean up ourselves.
            Person::where('email', $email)->delete();
            $actor->delete();
        }
    }
}
