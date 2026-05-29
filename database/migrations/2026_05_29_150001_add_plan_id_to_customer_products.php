<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            // Nullable so existing rows survive intact. The free-text
            // `plan` column stays for backwards compatibility and the
            // "custom price override" path where staff want a one-off
            // arrangement that doesn't match a defined plan.
            $table->foreignId('plan_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn('plan_id');
        });
    }
};
