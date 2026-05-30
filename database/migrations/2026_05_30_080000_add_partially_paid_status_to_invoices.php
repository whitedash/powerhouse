<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `partially_paid` to the invoices.status enum.
 *
 * The status set was previously {draft, sent, paid, overdue, void}
 * — every payment markPaid() received forced the row to `paid`
 * regardless of amount. A part-payment is a real state (operator
 * needs to chase the balance) and deserves its own status so it
 * filters correctly in the index and the dashboard.
 *
 * We're on MySQL; MODIFY COLUMN preserves data because we're only
 * adding a value to the enum, not removing or renaming any.
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status
            ENUM('draft','sent','partially_paid','paid','overdue','void')
            NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        // Down-migrate: collapse any partially_paid rows back to
        // 'sent' so the narrower enum accepts them. No row is
        // lost; the operator can re-record the payment after the
        // rollback. Safer than dropping to 'paid' which would
        // hide the outstanding balance.
        DB::statement("UPDATE invoices SET status = 'sent' WHERE status = 'partially_paid'");
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status
            ENUM('draft','sent','paid','overdue','void')
            NOT NULL DEFAULT 'draft'");
    }
};
