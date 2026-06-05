<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carries referral identity onto a lead at capture time (public form
 * submission with ?ref / wd_ref cookie), so LeadController::convert() can
 * hand it to AttributionService and produce a CustomerReferral instead of
 * dropping the referrer. Nullable — most leads have no referrer.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('referrer_id')->nullable()->after('source_detail')
                ->constrained('referrers')->nullOnDelete();
            $table->string('referral_code', 16)->nullable()->after('referrer_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['referrer_id']);
            $table->dropColumn(['referrer_id', 'referral_code']);
        });
    }
};
