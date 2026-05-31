<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-subscription label. A customer can now run several
 * subscriptions of the same product (e.g. one hosting plan per
 * website), so a short free-text label lets staff tell instances
 * apart — "Main website", "Blog", "Client A site".
 *
 * Note: there is intentionally no unique (customer_id, product_id)
 * constraint to drop — the existing composite index on those columns
 * is non-unique, which is exactly what allows multiple instances.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->string('label', 100)->nullable()->after('plan_price_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
