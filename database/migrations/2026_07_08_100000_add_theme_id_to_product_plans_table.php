<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plan theme override: a plan with its own theme_id renders (and
 * brands its Stripe payment step) with THAT theme; null falls back to
 * the product's theme, and a theme-less product keeps the default look.
 * Resolution chain lives in PlanThemeTokens::resolveForPlan().
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('product_plans', function (Blueprint $table): void {
            $table->foreignId('theme_id')->nullable()
                ->after('category_id')
                ->constrained('plan_themes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('theme_id');
        });
    }
};
