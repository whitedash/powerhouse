<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three opt-in columns wiring the subscriptions system into the
 * invoicing system. With auto_invoice on, the daily artisan job
 * (invoices:generate-subscriptions) creates a draft invoice on the
 * subscription's next_billing_date and rolls the date forward.
 *
 * auto_invoice_entity_id picks which BillingEntity heads the
 * invoice — defaults to the first active entity when null, so
 * setups with a single entity don't need to fill it in.
 *
 * last_invoiced_at is an audit breadcrumb the artisan command
 * writes after a successful generation. It's also handy for the
 * "when was the last invoice for this sub?" question without
 * joining back to invoices.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customer_products', function (Blueprint $table): void {
            $table->boolean('auto_invoice')
                ->default(false)
                ->after('next_billing_date');
            $table->foreignId('auto_invoice_entity_id')
                ->nullable()
                ->after('auto_invoice')
                ->constrained('billing_entities')
                ->nullOnDelete();
            $table->date('last_invoiced_at')
                ->nullable()
                ->after('auto_invoice_entity_id');

            // Compound index for the daily sweep — the command filters
            // on (status, auto_invoice, next_billing_date) so the
            // planner can resolve every condition from one btree walk.
            $table->index(
                ['status', 'auto_invoice', 'next_billing_date'],
                'customer_products_auto_invoice_sweep_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table): void {
            $table->dropIndex('customer_products_auto_invoice_sweep_idx');
            $table->dropForeign(['auto_invoice_entity_id']);
            $table->dropColumn(['auto_invoice', 'auto_invoice_entity_id', 'last_invoiced_at']);
        });
    }
};
