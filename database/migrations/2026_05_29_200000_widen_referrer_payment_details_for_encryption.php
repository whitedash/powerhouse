<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Switches referrers.payment_details from JSON to LONGTEXT so the
 * model can use the 'encrypted:array' cast.
 *
 * Encrypted casts emit a base64-encoded ciphertext payload that is
 * not valid JSON, so a JSON-typed column would reject it on write.
 * LONGTEXT removes that constraint and gives us plenty of room
 * (encrypted bank details run ~400-600 bytes plaintext, ~1 KB
 * encrypted+wrapped — fits comfortably).
 *
 * No data backfill: production currently has zero rows with
 * payment_details set, so we don't need to re-encrypt anything.
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE referrers MODIFY COLUMN payment_details LONGTEXT NULL');
    }

    public function down(): void
    {
        // Going back to JSON would reject any encrypted payload still
        // in the column. Null-out any non-JSON values first so the
        // rollback doesn't bomb on a row that's been encrypted since.
        DB::table('referrers')
            ->whereNotNull('payment_details')
            ->whereRaw('JSON_VALID(payment_details) = 0')
            ->update(['payment_details' => null]);

        DB::statement('ALTER TABLE referrers MODIFY COLUMN payment_details JSON NULL');
    }
};
