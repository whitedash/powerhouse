<?php

namespace Database\Seeders;

use App\Enums\PersonRole;
use App\Models\Company;
use App\Models\Person;
use Illuminate\Database\Seeder;

/**
 * LOCAL TESTING ONLY. Creates one person and links them to the first
 * 2–3 existing customers so the people↔companies UI has data to show.
 *
 * Deliberately NOT registered in DatabaseSeeder — run explicitly with:
 *   php artisan db:seed --class=PeopleSeeder
 *
 * Idempotent: re-running won't duplicate the person or the links
 * (firstOrCreate on email + syncWithoutDetaching on the pivot).
 */
class PeopleSeeder extends Seeder
{
    public function run(): void
    {
        $customers = Company::orderBy('id')->take(3)->get();

        if ($customers->count() < 2) {
            $this->command?->warn('PeopleSeeder: need at least 2 customers to demo multi-company ownership — skipping.');

            return;
        }

        $person = Person::firstOrCreate(
            ['email' => 'jordan.owner@example.com'],
            ['name' => 'Jordan Owner', 'phone' => '+44 7700 900123', 'notes' => 'Demo owner across multiple companies.'],
        );

        // owner of the first, director of the rest — shows two roles.
        $roles = [PersonRole::Owner, PersonRole::Director, PersonRole::Shareholder];

        foreach ($customers as $i => $customer) {
            $person->companies()->syncWithoutDetaching([
                $customer->id => [
                    'role' => ($roles[$i] ?? PersonRole::Other)->value,
                    'job_title' => $i === 0 ? 'Founder' : null,
                ],
            ]);
        }

        $this->command?->info("PeopleSeeder: linked '{$person->name}' to {$customers->count()} companies.");
    }
}
