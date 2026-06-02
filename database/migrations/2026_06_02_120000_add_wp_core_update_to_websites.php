<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WordPress core update availability for the WP Updates page. MainWP
 * reports a pending core upgrade under the site's wp_upgrades field as
 * {current, new}; the websites:sync-wordpress sweep distils that into a
 * boolean + the target version so the dashboard can show an amber "core
 * update available" banner without a live MainWP call per page view.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->boolean('wp_core_update_available')->default(false)->after('wp_version');
            $table->string('wp_latest_version', 20)->nullable()->after('wp_core_update_available');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['wp_core_update_available', 'wp_latest_version']);
        });
    }
};
