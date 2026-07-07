<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans widget: optional company/phone collected at checkout step 1 are
 * captured on the attempt row too — an abandoned-checkout follow-up is
 * precisely where a phone number is worth having.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('plan_checkout_attempts', function (Blueprint $table): void {
            $table->string('purchaser_company')->nullable()->after('purchaser_email');
            $table->string('purchaser_phone', 50)->nullable()->after('purchaser_company');
        });
    }

    public function down(): void
    {
        Schema::table('plan_checkout_attempts', function (Blueprint $table): void {
            $table->dropColumn(['purchaser_company', 'purchaser_phone']);
        });
    }
};
