<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `people` table is the cross-company human identity introduced to
 * support "one person owning multiple companies". It sits ALONGSIDE the
 * existing `contacts` layer (which stays a one-to-many per customer) —
 * a person is associated with companies through the `customer_person`
 * pivot, and an operational `contact` row may optionally point at a
 * person via the nullable `contacts.person_id`.
 *
 * `email` is nullable + unique: a person frequently has one, but we
 * never want two `people` rows to share an address (that would defeat
 * the dedupe the whole layer exists for). MySQL allows multiple NULLs
 * under a UNIQUE index, so email-less people coexist fine.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
