<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing P2: off-session collection prerequisites for the payments ledger.
 *
 *  - Add 'requires_action' to the status enum so an SCA (3-D Secure) off-session
 *    charge that needs customer authentication is recorded as a first-class
 *    outcome (not a failure, not a silent pending).
 *  - Make stripe_payment_intent_id UNIQUE so the inline command success and the
 *    async webhook converge on ONE ledger row (upsert keyed by PI id) and a
 *    PI can never be double-recorded. MySQL allows multiple NULLs in a unique
 *    index, so manual/bank rows (null PI) are unaffected.
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE payments MODIFY status ENUM('pending','succeeded','failed','requires_action') NOT NULL DEFAULT 'succeeded'"
        );

        Schema::table('payments', function (Blueprint $table): void {
            $table->unique('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['stripe_payment_intent_id']);
        });

        DB::statement(
            "ALTER TABLE payments MODIFY status ENUM('pending','succeeded','failed') NOT NULL DEFAULT 'succeeded'"
        );
    }
};
