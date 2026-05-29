<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // 1) Add the new columns. Default 0 / 1 / 'month' so any
        //    pre-existing row has a valid shape before backfill.
        Schema::table('product_plans', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0)->after('name');
            $table->unsignedTinyInteger('interval_count')->default(1)->after('price');
            $table->enum('interval_unit', ['day', 'week', 'month', 'year', 'one_time'])
                ->default('month')
                ->after('interval_count');
            $table->string('stripe_price_id', 100)->nullable()->after('interval_unit');
        });

        // 2) Backfill from the old shape. Annual plans become a separate
        //    concept (caller picks the plan they want), so we promote
        //    price_monthly into the canonical price column — no annual
        //    plan rows survive automatically. If a plan only had an
        //    annual price set the operator will need to add a new plan
        //    after migration; flagging that in the commit message.
        DB::statement('UPDATE product_plans SET price = price_monthly, stripe_price_id = stripe_price_id_monthly');

        // 3) Drop the four old columns.
        Schema::table('product_plans', function (Blueprint $table) {
            $table->dropColumn([
                'price_monthly',
                'price_annual',
                'stripe_price_id_monthly',
                'stripe_price_id_annual',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('product_plans', function (Blueprint $table) {
            $table->decimal('price_monthly', 10, 2)->default(0)->after('name');
            $table->decimal('price_annual', 10, 2)->nullable()->after('price_monthly');
            $table->string('stripe_price_id_monthly', 100)->nullable()->after('features');
            $table->string('stripe_price_id_annual', 100)->nullable()->after('stripe_price_id_monthly');
        });

        DB::statement('UPDATE product_plans SET price_monthly = price, stripe_price_id_monthly = stripe_price_id');

        Schema::table('product_plans', function (Blueprint $table) {
            $table->dropColumn(['price', 'interval_count', 'interval_unit', 'stripe_price_id']);
        });
    }
};
