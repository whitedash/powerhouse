<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track how a customer found us. The values map to the icon set on
 * the new-customer slide-over; `channel_detail` is the free-text
 * answer to "which platform / campaign / event" for the channels
 * where a generic label isn't enough.
 *
 * Nullable because every existing customer was created before
 * this column landed — we don't want to retro-attribute them.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->enum('acquisition_channel', [
                'direct', 'google', 'social_media', 'landing_page',
                'referral', 'email', 'event', 'word_of_mouth', 'other',
            ])->nullable()->after('pipeline_stage');
            $table->string('channel_detail')->nullable()->after('acquisition_channel');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['acquisition_channel', 'channel_detail']);
        });
    }
};
