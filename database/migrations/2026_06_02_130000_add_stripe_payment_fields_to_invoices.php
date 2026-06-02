<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles schema drift: these Stripe payment columns already exist on
 * the live `invoices` table but were never captured in a migration (so a
 * `migrate:fresh` would silently drop them). The `hasColumn` guards make
 * this a no-op on the current database while restoring reproducibility on
 * a fresh build. See SCHEMA.md → invoices.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'stripe_payment_intent_id')) {
                $table->string('stripe_payment_intent_id', 100)->nullable()->after('qbo_invoice_id');
            }
            if (! Schema::hasColumn('invoices', 'stripe_checkout_session_id')) {
                $table->string('stripe_checkout_session_id', 100)->nullable()->after('stripe_payment_intent_id');
            }
            if (! Schema::hasColumn('invoices', 'stripe_payment_link')) {
                $table->string('stripe_payment_link', 500)->nullable()->after('stripe_checkout_session_id');
            }
            if (! Schema::hasColumn('invoices', 'paid_via')) {
                // Which channel cleared the balance — manual = the
                // staff-recorded markPaid() flow, stripe = online checkout,
                // bank = direct transfer reconciled by hand.
                $table->enum('paid_via', ['manual', 'stripe', 'bank'])->nullable()->after('stripe_payment_link');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach (['stripe_payment_intent_id', 'stripe_checkout_session_id', 'stripe_payment_link', 'paid_via'] as $col) {
                if (Schema::hasColumn('invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
