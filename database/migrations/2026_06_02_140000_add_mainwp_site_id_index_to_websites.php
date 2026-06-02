<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WordPress Updates screen filters every load on
 * whereNotNull('mainwp_site_id')->where('status', 'active'), but
 * mainwp_site_id was unindexed — a filtered table scan at scale.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->index(['mainwp_site_id', 'status'], 'websites_mainwp_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex('websites_mainwp_status_idx');
        });
    }
};
