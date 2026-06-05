<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer↔person many-to-many: which companies a person owns or is
 * associated with, and in what capacity (`role`). Named `customer_person`
 * to match Laravel's singular-alphabetical pivot convention so the
 * Customer::people() / Person::companies() belongsToMany relations need
 * no extra wiring.
 *
 * `role` is stored as a plain string, NOT a DB enum — App\Enums\PersonRole
 * is the single source of truth for the allowed values (the pivot model
 * casts the column to it). This deliberately avoids the value-list
 * triplication that the legacy contacts.role enum suffers from.
 *
 * The compound UNIQUE (customer_id, person_id) enforces one association
 * row per person-per-company; the role can change but the pair can't
 * duplicate.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('customer_person', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role', 32)->default('owner');
            $table->string('job_title', 100)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_person');
    }
};
