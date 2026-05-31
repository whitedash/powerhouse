<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relocate the hosting flag from products to product_plans. Whether a
 * subscription counts as "hosting" is a plan-level decision — a single
 * product can carry both hosting and non-hosting plans — so the flag
 * belongs on the plan, alongside the existing disk/email/bandwidth
 * allowances.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_hosting', 'is_active']);
            $table->dropColumn('is_hosting');
        });

        Schema::table('product_plans', function (Blueprint $table) {
            $table->boolean('is_hosting')->default(false)->after('is_active');
            $table->index(['is_hosting']);
        });
    }

    public function down(): void
    {
        Schema::table('product_plans', function (Blueprint $table) {
            $table->dropIndex(['is_hosting']);
            $table->dropColumn('is_hosting');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_hosting')->default(false)->after('is_coming_soon');
            $table->index(['is_hosting', 'is_active']);
        });
    }
};
