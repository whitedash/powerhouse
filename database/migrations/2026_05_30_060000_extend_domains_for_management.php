<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing domains row with the six fields the new
 * Domains & DNS management surface needs. Doesn't rename the
 * existing columns (expiry_date / ssl_expiry_date / last_synced_at)
 * — the audit log and the dashboard's "Needs Attention" card both
 * already read those names, and renaming would force a data migration
 * across the activity_log rows that reference them.
 *
 * status + ssl_status carry computed health flags so the index page
 * and the artisan checker don't have to recompute them on every read.
 * The check command writes back whenever it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->date('registered_at')->nullable()->after('domain');
            $table->boolean('auto_renew')->default(false)->after('registered_at');
            $table->enum('status', [
                'active',
                'expiring_soon',
                'expired',
                'parked',
                'transferred',
            ])->default('active')->after('auto_renew');
            $table->enum('ssl_status', [
                'active',
                'expiring',
                'expired',
                'none',
            ])->default('none')->after('ssl_expiry_date');
            $table->json('nameservers')->nullable()->after('ssl_status');
            // Operator-facing notes for the new management surface.
            // Kept separate from the legacy hosting_notes column so
            // the existing customer-page card doesn't show generic
            // domain notes alongside hosting-specific ones.
            $table->text('notes')->nullable()->after('hosting_notes');

            $table->index('status', 'domains_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropIndex('domains_status_idx');
            $table->dropColumn([
                'registered_at',
                'auto_renew',
                'status',
                'ssl_status',
                'nameservers',
                'notes',
            ]);
        });
    }
};
