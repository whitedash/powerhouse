<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Websites — the hosting/site register. One row per managed site, linked
 * (without duplicating) to the customer, their hosting subscription, the
 * domain record, and the build project. cPanel credentials live per-site
 * (token encrypted); WHM suspension is gated on whm_managed. Usage,
 * PageSpeed and WordPress columns are populated by their respective
 * sync jobs and start null.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            // RESTRICT — a site is a real asset; deleting a customer with
            // live sites should be an explicit, deliberate cleanup.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            // Identity
            $table->string('name');
            $table->string('url', 500);

            // Links to existing records (no duplication)
            $table->foreignId('customer_product_id')->nullable()
                ->constrained('customer_products')->nullOnDelete();
            $table->foreignId('domain_id')->nullable()
                ->constrained('domains')->nullOnDelete();
            $table->foreignId('project_id')->nullable()
                ->constrained('projects')->nullOnDelete();

            // cPanel access (per site)
            $table->string('cpanel_username', 100)->nullable();
            $table->text('cpanel_token')->nullable(); // encrypted cast on the model
            $table->string('cpanel_server')->nullable()->default('040hosting.eu');
            $table->boolean('whm_managed')->default(false);

            // Hosting usage (cPanel UAPI)
            $table->unsignedInteger('disk_used_mb')->nullable();
            $table->unsignedInteger('disk_quota_mb')->nullable();
            $table->unsignedSmallInteger('email_accounts_count')->nullable();
            $table->unsignedSmallInteger('email_accounts_quota')->nullable();
            $table->unsignedInteger('bandwidth_used_mb')->nullable();
            $table->unsignedInteger('bandwidth_quota_mb')->nullable();
            $table->timestamp('usage_checked_at')->nullable();

            // WordPress (MainWP, later)
            $table->unsignedInteger('mainwp_site_id')->nullable();
            $table->string('wp_version', 20)->nullable();
            $table->string('php_version', 20)->nullable();
            $table->unsignedSmallInteger('plugins_total')->default(0);
            $table->unsignedSmallInteger('plugins_outdated')->default(0);
            $table->unsignedSmallInteger('themes_outdated')->default(0);
            $table->timestamp('last_backup_at')->nullable();

            // PageSpeed (Google PSI)
            $table->unsignedTinyInteger('pagespeed_mobile')->nullable();
            $table->unsignedTinyInteger('pagespeed_desktop')->nullable();
            $table->decimal('pagespeed_lcp', 5, 2)->nullable();
            $table->decimal('pagespeed_cls', 5, 3)->nullable();
            $table->decimal('pagespeed_fcp', 5, 2)->nullable();
            $table->unsignedInteger('pagespeed_tbt')->nullable();
            $table->json('pagespeed_data')->nullable();
            $table->timestamp('pagespeed_checked_at')->nullable();

            // Analytics (future — GA4)
            $table->string('ga4_property_id', 50)->nullable();
            $table->unsignedInteger('monthly_visitors')->nullable();
            $table->timestamp('analytics_updated_at')->nullable();

            $table->enum('status', ['active', 'suspended', 'migrating', 'cancelled'])->default('active');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'status'], 'websites_customer_status_idx');
            $table->index('cpanel_username', 'websites_cpanel_user_idx');
            $table->index('pagespeed_checked_at', 'websites_pagespeed_checked_idx');
            $table->index('usage_checked_at', 'websites_usage_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
