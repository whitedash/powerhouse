<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // STEP 1 — Backfill: one ProductPlanPrice per existing
        // ProductPlan, marked as the plan's default. This must run
        // before the old pricing columns are dropped. Strict 1:1 —
        // any duplicate plan rows the operator created with different
        // intervals stay as separate plans; merging them is a manual
        // task post-migration via the new UI.
        $now = now();
        $plans = DB::table('product_plans')->get();
        foreach ($plans as $plan) {
            DB::table('product_plan_prices')->insert([
                'plan_id' => $plan->id,
                'price' => $plan->price ?? 0,
                'interval_count' => $plan->interval_count ?? 1,
                'interval_unit' => $plan->interval_unit ?? 'month',
                'stripe_price_id' => $plan->stripe_price_id ?? null,
                'label' => null,
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // STEP 2 — Add category_id to product_plans.
        Schema::table('product_plans', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_plan_categories')
                ->nullOnDelete();
        });

        // STEP 3 — Drop pricing columns from product_plans (pricing now
        // lives entirely on product_plan_prices).
        Schema::table('product_plans', function (Blueprint $table) {
            $table->dropColumn(['price', 'interval_count', 'interval_unit', 'stripe_price_id']);
        });

        // STEP 4 — Add plan_price_id to customer_products + backfill
        // from each sub's plan's default price (no-op for our dev DB
        // which has zero subscription rows, still safe for prod data).
        Schema::table('customer_products', function (Blueprint $table) {
            $table->foreignId('plan_price_id')
                ->nullable()
                ->after('plan_id')
                ->constrained('product_plan_prices')
                ->nullOnDelete();
        });

        DB::statement('UPDATE customer_products cp
            JOIN product_plan_prices pp
              ON pp.plan_id = cp.plan_id AND pp.is_default = 1
            SET cp.plan_price_id = pp.id
            WHERE cp.plan_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->dropForeign(['plan_price_id']);
            $table->dropColumn('plan_price_id');
        });

        Schema::table('product_plans', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0)->after('name');
            $table->unsignedTinyInteger('interval_count')->default(1)->after('price');
            $table->enum('interval_unit', ['day', 'week', 'month', 'year', 'one_time'])
                ->default('month')
                ->after('interval_count');
            $table->string('stripe_price_id', 100)->nullable()->after('interval_unit');
        });

        // Restore pricing onto product_plans from each plan's default
        // (or first) price before dropping the prices table.
        DB::statement('UPDATE product_plans p
            LEFT JOIN product_plan_prices pp
              ON pp.plan_id = p.id AND pp.is_default = 1
            SET p.price = COALESCE(pp.price, 0),
                p.interval_count = COALESCE(pp.interval_count, 1),
                p.interval_unit = COALESCE(pp.interval_unit, "month"),
                p.stripe_price_id = pp.stripe_price_id');

        Schema::table('product_plans', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
