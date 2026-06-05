<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deal-registration lifecycle on leads — SEPARATE from the pipeline
 * `status` enum (untouched here). referral_status is nullable: NULL means
 * "not a deal-registration", so every existing lead (manual + cookie-
 * attributed) is unaffected. Protection clock (protected_until) starts at
 * approval, fixed 90-day window.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->enum('referral_status', ['pending_review', 'approved', 'rejected', 'expired'])
                ->nullable()
                ->after('referral_code');
            $table->timestamp('registered_at')->nullable()->after('referral_status');
            $table->timestamp('protected_until')->nullable()->after('registered_at');
            $table->foreignId('reviewed_by')->nullable()->after('protected_until')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');
            $table->timestamp('referral_consent_at')->nullable()->after('review_notes');

            $table->index('referral_status');
            $table->index('protected_until');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex(['referral_status']);
            $table->dropIndex(['protected_until']);
            $table->dropColumn([
                'referral_status',
                'registered_at',
                'protected_until',
                'reviewed_by',
                'reviewed_at',
                'review_notes',
                'referral_consent_at',
            ]);
        });
    }
};
