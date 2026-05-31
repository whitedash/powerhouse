<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspension audit trail for customer_products. The `status` enum
 * already carries 'suspended'; these columns record *why* and *by whom*
 * so an auto-suspension (suspended_by = null) is distinguishable from a
 * staff action, and so a reinstatement can be attributed and reasoned.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->enum('suspension_reason', [
                'non_payment', 'manual', 'trial_ended', 'fraud', 'other',
            ])->nullable()->after('status');

            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
            // null suspended_by = auto-suspended by the system.
            $table->foreignId('suspended_by')->nullable()->after('suspended_at')
                ->constrained('users')->nullOnDelete();

            $table->text('reinstatement_reason')->nullable()->after('suspended_by');
            $table->timestamp('reinstated_at')->nullable()->after('reinstatement_reason');
            // null reinstated_by = auto-reinstated via payment.
            $table->foreignId('reinstated_by')->nullable()->after('reinstated_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropConstrainedForeignId('reinstated_by');
            $table->dropColumn([
                'suspension_reason', 'suspended_at',
                'reinstatement_reason', 'reinstated_at',
            ]);
        });
    }
};
