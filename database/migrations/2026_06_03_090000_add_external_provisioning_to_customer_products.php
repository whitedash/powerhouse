<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provisioning bookkeeping for consumer apps that Powerhouse creates an
 * account in (currently MyOrderPad). external_user_id is the consumer's
 * own user identifier returned at provision time; the SSO token carries it
 * so the consumer can resolve the account on auto-login.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->string('external_user_id')->nullable()->after('product_id');
            $table->string('external_email')->nullable()->after('external_user_id');
            $table->timestamp('provisioned_at')->nullable()->after('external_email');
            $table->string('provision_status')->default('pending')->after('provisioned_at');

            // Fast lookup when reconciling a provisioned account back to its
            // subscription, and for the "needs provisioning" sweep.
            $table->index(['provision_status', 'external_user_id'], 'cp_provision_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->dropIndex('cp_provision_idx');
            $table->dropColumn([
                'external_user_id',
                'external_email',
                'provisioned_at',
                'provision_status',
            ]);
        });
    }
};
