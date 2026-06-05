<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends customer_referrals IN PLACE (this is the spec's "referrals"
 * table — we do NOT create a new one). Adds the attribution provenance:
 * which lead/click produced it, the product + campaign, and how it was
 * sourced. The unique(customer_id) constraint (from the create migration)
 * still guarantees one immutable attribution per customer — last-touch is
 * resolved BEFORE insert, in AttributionService.
 *
 * updated_at is added for column convention even though rows are
 * immutable (the model keeps $timestamps=false); harmless, forward-compat.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customer_referrals', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('referrer_id')
                ->constrained('leads')->nullOnDelete();
            $table->foreignId('click_id')->nullable()->after('lead_id')
                ->constrained('referral_clicks')->nullOnDelete();
            $table->string('product', 30)->nullable()->after('click_id');
            $table->string('source', 20)->default('manual')->after('product');
            $table->string('campaign', 100)->nullable()->after('source');
            $table->timestamp('converted_at')->nullable()->after('attributed_at');
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_referrals', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropForeign(['click_id']);
            $table->dropColumn(['lead_id', 'click_id', 'product', 'source', 'campaign', 'converted_at', 'updated_at']);
        });
    }
};
