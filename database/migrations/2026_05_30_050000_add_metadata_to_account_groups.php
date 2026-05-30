<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing account_groups table (created in the legacy
 * 2026_05_28 migration) with the metadata the new Customer Groups UI
 * needs. We don't rename the table — Customer.groups() and the
 * customer_group_memberships pivot already key off account_groups,
 * and a rename would force a data migration for callers that read
 * the relation. Instead the user-facing UI calls them "Customer
 * groups" while the model + table stay AccountGroup / account_groups.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('account_groups', function (Blueprint $table): void {
            $table->string('description', 500)->nullable()->after('name');
            // Hex colour. 7 chars holds "#RRGGBB"; null falls back to
            // the neutral chip palette in the UI.
            $table->string('colour', 7)->nullable()->after('description');
            $table->foreignId('created_by')
                ->nullable()
                ->after('colour')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_groups', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['description', 'colour', 'created_by']);
        });
    }
};
