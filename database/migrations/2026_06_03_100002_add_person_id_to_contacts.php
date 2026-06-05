<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional link from an operational contact to a cross-company person.
 *
 * Nullable + nullOnDelete so this is purely additive: existing contacts
 * survive untouched (no backfill in v1), the one-to-many on customer_id
 * is unchanged, and deleting a person simply clears the link rather than
 * cascading into / orphaning the contact and its portal-login history.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('person_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('people')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropColumn('person_id');
        });
    }
};
