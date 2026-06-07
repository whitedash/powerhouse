<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_plans.is_domain — sibling to is_hosting. Flags a plan as a
 * DOMAIN-registration plan so the domain management UI can offer only
 * domain plans as the price source, and so domain renewals price off the
 * plan's product_plan_prices (like hosting) without becoming subscriptions.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('product_plans', function (Blueprint $table): void {
            $table->boolean('is_domain')->default(false)->after('is_hosting');
            $table->index(['is_domain', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('product_plans', function (Blueprint $table): void {
            $table->dropIndex(['is_domain', 'is_active']);
            $table->dropColumn('is_domain');
        });
    }
};
