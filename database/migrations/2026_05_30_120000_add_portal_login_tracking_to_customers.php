<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal login activity counters on the Customer record.
 *
 * Why on customers (not portal_users): portal_users already
 * tracks per-user last_login_at. The customer-level fields are
 * an aggregate for the SSO sprint — "how often has anyone from
 * this account signed in", used by:
 *
 *   - Portal/Dashboard.vue "Your products" surface to nudge
 *     dormant accounts.
 *   - OAuth UserInfoController to expose engagement signal
 *     to consumer apps without leaking per-contact PII.
 *
 * PortalAuthController::login() bumps the count + stamps the
 * timestamp on every successful portal login so OAuth flows
 * triggered through SSO don't need their own hook.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('portal_last_login_at')->nullable()
                ->after('updated_at');
            $table->unsignedInteger('portal_login_count')->default(0)
                ->after('portal_last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['portal_last_login_at', 'portal_login_count']);
        });
    }
};
