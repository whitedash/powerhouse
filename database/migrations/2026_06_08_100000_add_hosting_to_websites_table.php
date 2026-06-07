<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 1a: hosting becomes a property of the website. A website now carries
 * its own hosting plan (catalog product_plan + price tier) plus billing-anchor
 * columns mirroring CustomerProduct's. The anchor columns (auto_invoice /
 * next_billing_date / last_invoiced_at) are inert here — Stage 1b adds the
 * per-website hosting billing path that reads them.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            // Hosting plan carried directly on the website (the catalog
            // is_hosting plan + its chosen price tier). nullOnDelete so a
            // retired plan/price never orphans a website row.
            $table->foreignId('plan_id')->nullable()->after('customer_product_id')
                ->constrained('product_plans')->nullOnDelete();
            $table->foreignId('plan_price_id')->nullable()->after('plan_id')
                ->constrained('product_plan_prices')->nullOnDelete();

            // Lifecycle of the hosting attachment. none = no hosting plan;
            // active = on hosting; suspended = reserved for Stage 1b.
            $table->enum('hosting_status', ['none', 'active', 'suspended'])
                ->default('none')->after('plan_price_id');
            $table->timestamp('hosting_started_at')->nullable()->after('hosting_status');

            // Billing anchors — mirror customer_products.{auto_invoice,
            // next_billing_date,last_invoiced_at}. NOT read by any code yet;
            // Stage 1b wires the per-website hosting invoice sweep.
            $table->boolean('hosting_auto_invoice')->default(false)->after('hosting_started_at');
            $table->date('hosting_next_billing_date')->nullable()->after('hosting_auto_invoice');
            $table->date('hosting_last_invoiced_at')->nullable()->after('hosting_next_billing_date');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropConstrainedForeignId('plan_price_id');
            $table->dropColumn([
                'hosting_status',
                'hosting_started_at',
                'hosting_auto_invoice',
                'hosting_next_billing_date',
                'hosting_last_invoiced_at',
            ]);
        });
    }
};
