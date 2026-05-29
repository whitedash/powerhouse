<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'pending' to the customer_products.status enum so the portal
 * self-service signup flow can create rows that staff approve from
 * the internal Provisioning page. Without this, customer-initiated
 * subscribe requests have nowhere to live between submission and
 * activation — the existing trial/active values both imply the sub
 * is already live.
 *
 * MySQL doesn't let us alter an enum declaratively from Laravel's
 * Schema builder, so this is a raw ALTER. The order of values is
 * unchanged for the rest of the set — only 'pending' is appended.
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE customer_products MODIFY COLUMN status ENUM('active','trial','suspended','cancelled','pending') NOT NULL DEFAULT 'trial'");
    }

    public function down(): void
    {
        // Any rows still in 'pending' would block the enum shrink — flip
        // them to 'trial' so the rollback is non-destructive of access
        // state. (A pending sub never had access, so trial is the safe
        // approximation.)
        DB::table('customer_products')->where('status', 'pending')->update(['status' => 'trial']);

        DB::statement("ALTER TABLE customer_products MODIFY COLUMN status ENUM('active','trial','suspended','cancelled') NOT NULL DEFAULT 'trial'");
    }
};
