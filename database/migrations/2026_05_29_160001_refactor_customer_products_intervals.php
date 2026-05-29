<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->unsignedTinyInteger('interval_count')->default(1)->after('billing_interval');
            $table->enum('interval_unit', ['day', 'week', 'month', 'year', 'one_time'])
                ->default('month')
                ->after('interval_count');
        });

        // Backfill from billing_interval. annual stays at month×12 (so
        // the existing MRR math via mrr_contribution still amortises
        // correctly); one_off → one_time × 1.
        DB::statement("UPDATE customer_products
            SET
                interval_count = CASE billing_interval
                    WHEN 'monthly' THEN 1
                    WHEN 'annual'  THEN 12
                    WHEN 'one_off' THEN 1
                    ELSE 1
                END,
                interval_unit = CASE billing_interval
                    WHEN 'monthly' THEN 'month'
                    WHEN 'annual'  THEN 'month'
                    WHEN 'one_off' THEN 'one_time'
                    ELSE 'month'
                END");

        Schema::table('customer_products', function (Blueprint $table) {
            // billing_interval has an index from the earlier sprint —
            // drop the index before the column, otherwise MySQL refuses.
            $table->dropIndex(['billing_interval']);
            $table->dropColumn('billing_interval');
        });
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->enum('billing_interval', ['monthly', 'annual', 'one_off'])
                ->default('monthly')
                ->after('price_monthly');
        });

        DB::statement("UPDATE customer_products
            SET billing_interval = CASE
                WHEN interval_unit = 'one_time' THEN 'one_off'
                WHEN interval_unit = 'month' AND interval_count = 12 THEN 'annual'
                ELSE 'monthly'
            END");

        Schema::table('customer_products', function (Blueprint $table) {
            $table->index('billing_interval');
            $table->dropColumn(['interval_count', 'interval_unit']);
        });
    }
};
