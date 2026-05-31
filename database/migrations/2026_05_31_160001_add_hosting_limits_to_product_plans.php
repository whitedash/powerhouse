<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hosting allowances for product plans. Nullable — only hosting plans
 * carry them; everything else leaves them null. The websites detail
 * surfaces these as the quota a site's usage is measured against when
 * cPanel hasn't reported its own limit.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('product_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('disk_quota_gb')->nullable()->after('features');
            $table->unsignedSmallInteger('email_quota')->nullable()->after('disk_quota_gb');
            $table->unsignedSmallInteger('bandwidth_quota_gb')->nullable()->after('email_quota');
        });
    }

    public function down(): void
    {
        Schema::table('product_plans', function (Blueprint $table) {
            $table->dropColumn(['disk_quota_gb', 'email_quota', 'bandwidth_quota_gb']);
        });
    }
};
