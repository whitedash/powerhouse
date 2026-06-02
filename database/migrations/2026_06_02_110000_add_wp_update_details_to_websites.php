<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detailed per-item update payloads for the WP Updates page. The
 * websites:sync-wordpress sweep already records plugins_outdated /
 * themes_outdated counts; these JSON columns store the slim breakdown
 * (name, slug, current → new version) so the dashboard can show exactly
 * which plugins/themes are out of date without a live MainWP call per
 * page view. Only the four fields are stored — never MainWP's bundled
 * changelog HTML.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->json('plugin_updates_detail')->nullable()->after('plugins_outdated');
            $table->json('theme_updates_detail')->nullable()->after('themes_outdated');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['plugin_updates_detail', 'theme_updates_detail']);
        });
    }
};
