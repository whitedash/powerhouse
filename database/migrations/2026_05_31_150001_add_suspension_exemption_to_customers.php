<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets staff exempt specific customers from the auto-suspension sweep
 * (e.g. a strategic account being handled manually). The reason is
 * captured for the audit trail and surfaced in the customer header.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('exempt_from_auto_suspend')->default(false)->after('referred_by');
            $table->string('exempt_reason', 500)->nullable()->after('exempt_from_auto_suspend');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['exempt_from_auto_suspend', 'exempt_reason']);
        });
    }
};
