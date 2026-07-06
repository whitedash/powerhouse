<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans widget foundation (PLANS-WIDGET-DESIGN.md §1): per-plan gate for
 * self-serve purchases. false (default) = a webhook-settled purchase
 * provisions live immediately; true = records are created held "pending"
 * (customer_products.status='pending', receipt withheld) until a staff
 * member confirms. Column only in this branch — the widget, checkout
 * endpoint, and webhook branch that read it come in follow-up branches.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('product_plans', function (Blueprint $table): void {
            $table->boolean('requires_manual_review')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('product_plans', function (Blueprint $table): void {
            $table->dropColumn('requires_manual_review');
        });
    }
};
