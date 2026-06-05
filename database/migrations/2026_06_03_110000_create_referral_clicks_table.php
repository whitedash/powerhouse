<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw click events on a referral link (/r/{code}). One row per valid-code
 * hit — invalid codes are not logged in v1. These are immutable analytics
 * events (no updated_at) and the source the AttributionService reads for
 * last-touch attribution within the 60-day window.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('referral_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('referrers')->cascadeOnDelete();
            // Denormalised alongside referrer_id so the last-touch lookup
            // (by code, newest within window) is a single indexed scan.
            $table->string('referral_code', 16);
            // The `p` param — a ProductKey value (validated; unknown ignored).
            $table->string('product', 30)->nullable();
            // The `c` param — campaign tag (length-capped at ingest).
            $table->string('campaign', 100)->nullable();
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('landing_url', 2048)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('referrer_id');
            $table->index('referral_code');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_clicks');
    }
};
