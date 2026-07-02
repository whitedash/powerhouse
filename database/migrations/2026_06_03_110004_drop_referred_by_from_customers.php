<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the dead customers.referred_by column. It was created with an
 * index but NO foreign key and was never wired up — the canonical
 * attribution path is the customer_referrals pivot. A codebase grep
 * confirmed the only readers were three references in the Company model
 * (removed in the same change); no controller, service, route, Blade or
 * Vue touched it.
 *
 * down() restores the column as it originally existed (nullable + index,
 * no FK) so a rollback returns to the prior schema exactly.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_referred_by_index');
            $table->dropColumn('referred_by');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('referred_by')->nullable()->index()->after('assigned_to');
        });
    }
};
