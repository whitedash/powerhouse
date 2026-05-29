<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rounds out the contacts table for the full CRUD UI.
 *
 *  - job_title: free-text role (e.g. "Head Chef", "Accounts manager").
 *    Kept separate from the existing `role` enum because that field
 *    governs invoice/portal permissions and shouldn't be touched by
 *    a casual "what's your title?" edit.
 *  - notes: short scratchpad about the contact (preferred contact
 *    times, known nicknames, etc.) — not a customer-wide note, just
 *    tied to this one contact.
 *  - email now nullable: a primary contact must have an email (the
 *    invoice + portal-invite flow depends on it), but a secondary
 *    "phone only" contact is a legitimate shape.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('job_title', 100)->nullable()->after('phone');
            $table->text('notes')->nullable()->after('is_primary');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Re-tightening email to NOT NULL would blow up on any row with
        // a null email — backfill those to '' first.
        DB::table('contacts')->whereNull('email')->update(['email' => '']);

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->dropColumn(['job_title', 'notes']);
        });
    }
};
