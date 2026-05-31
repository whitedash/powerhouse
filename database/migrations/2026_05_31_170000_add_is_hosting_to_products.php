<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flags a product as a hosting product. When enabled the product's
 * subscriptions surface in the website hosting-plan selector on the
 * customer Websites tab. The composite (is_hosting, is_active) index
 * keeps that lookup cheap — we only ever ask for active hosting rows.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_hosting')->default(false)->after('is_coming_soon');
            $table->index(['is_hosting', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_hosting', 'is_active']);
            $table->dropColumn('is_hosting');
        });
    }
};
