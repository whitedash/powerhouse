<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe payment fields on invoices. stripe_checkout_session_id +
 * stripe_payment_link are written when a hosted-checkout link is created;
 * stripe_payment_intent_id is filled by the webhook on payment. paid_via
 * records the settlement channel (distinct from payment_method, which is
 * the operator-entered manual-payment instrument).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('stripe_payment_intent_id', 100)->nullable()->after('qbo_invoice_id');
            $table->string('stripe_checkout_session_id', 100)->nullable()->after('stripe_payment_intent_id');
            $table->string('stripe_payment_link', 500)->nullable()->after('stripe_checkout_session_id');
            $table->enum('paid_via', ['manual', 'stripe', 'bank'])->nullable()->after('stripe_payment_link');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_payment_intent_id',
                'stripe_checkout_session_id',
                'stripe_payment_link',
                'paid_via',
            ]);
        });
    }
};
