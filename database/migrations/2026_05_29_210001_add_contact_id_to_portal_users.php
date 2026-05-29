<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-contact portal access. Each PortalUser is now optionally tied
 * to the specific Contact row that owns the account, so a customer
 * with three contacts can invite each one to the portal as a
 * distinct login.
 *
 * Nullable so existing rows survive the migration without a
 * backfill; SET NULL on delete so that wiping a contact doesn't
 * cascade and orphan login history.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('portal_users', function (Blueprint $table) {
            $table->foreignId('contact_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('portal_users', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropColumn('contact_id');
        });
    }
};
