<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link expenses to the new suppliers table.
 *
 * The original `supplier` column was a free-text payee name. We rename
 * it to `supplier_name` so the FK relationship `Expense::supplier()`
 * doesn't collide with an attribute of the same name, and it stays as a
 * legacy/ad-hoc fallback when no supplier record is linked. The rename
 * is safe — there are no rows depending on the old name yet — and any
 * future ad-hoc expense (no supplier_id) still records a free-text name.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->renameColumn('supplier', 'supplier_name');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('supplier_name')
                ->constrained('suppliers')->nullOnDelete();

            // QBO alignment — the bill this expense maps to in QuickBooks.
            $table->string('qbo_bill_id', 100)->nullable()->unique()->after('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('qbo_bill_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->renameColumn('supplier_name', 'supplier');
        });
    }
};
