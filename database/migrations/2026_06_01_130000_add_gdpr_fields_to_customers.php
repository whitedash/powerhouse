<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GDPR tooling (Articles 17 + 20). erasure_requested_at flags an erasure
 * request; erasure_completed_at stamps the anonymisation once it's legally
 * permitted (all invoices settled). data_export_last_at records the most
 * recent Article 20 export. erasure_requested_by is SET NULL on user delete
 * so the audit timestamp survives even if the requesting staffer leaves.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('erasure_requested_at')->nullable()->after('qbo_customer_id');
            $table->timestamp('erasure_completed_at')->nullable()->after('erasure_requested_at');
            $table->foreignId('erasure_requested_by')->nullable()->after('erasure_completed_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('data_export_last_at')->nullable()->after('erasure_requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['erasure_requested_by']);
            $table->dropColumn([
                'erasure_requested_at',
                'erasure_completed_at',
                'erasure_requested_by',
                'data_export_last_at',
            ]);
        });
    }
};
