<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA tracking (Support Sprint 1). first_responded_at is stamped on the
 * first staff, non-internal reply; reopened_at/reopen_count track
 * resolved|closed → open transitions. sla_breach_at is left as-is — it
 * already equals created_at + first-response hours, so we now treat it as
 * the first-response DEADLINE. Breach is computed on read, not stored.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->timestamp('first_responded_at')->nullable()->after('sla_breach_at');
            $table->timestamp('reopened_at')->nullable()->after('first_responded_at');
            $table->unsignedInteger('reopen_count')->default(0)->after('reopened_at');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['first_responded_at', 'reopened_at', 'reopen_count']);
        });
    }
};
