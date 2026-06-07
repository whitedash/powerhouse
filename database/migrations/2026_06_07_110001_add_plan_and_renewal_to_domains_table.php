<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains gain a pricing link + a renewal-billing idempotency marker.
 *
 *  - product_plan_id : nullable FK -> product_plans (nullOnDelete). The
 *    PRICE SOURCE for renewal billing. Domains stay first-class in their
 *    own table (registrar/expiry/DNS/auto_renew) — the plan is pricing only;
 *    a domain is NOT a CustomerProduct, so the subscription cron ignores it.
 *  - renewal_invoiced_for : the expiry_date the last renewal invoice was
 *    raised for. The renewal command bills only when this differs from the
 *    current expiry_date, so each expiry cycle is billed exactly once and
 *    the next cycle becomes billable when a registrar sync advances expiry.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->foreignId('product_plan_id')->nullable()->after('auto_renew')
                ->constrained('product_plans')->nullOnDelete();
            $table->date('renewal_invoiced_for')->nullable()->after('product_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropForeign(['product_plan_id']);
            $table->dropColumn(['product_plan_id', 'renewal_invoiced_for']);
        });
    }
};
