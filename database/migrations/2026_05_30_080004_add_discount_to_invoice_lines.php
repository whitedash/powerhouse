<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line-level discounts on invoices.
 *
 * Two-mode UI (percentage vs fixed) means we need both `type` and
 * `value`. The resulting `discount_amount` is stored for audit so
 * we don't have to re-derive it from the line total when the
 * customer asks "why did I get £4.80 off?". Nullable across the
 * board: most lines have no discount and we don't want a default
 * value that the operator has to remove.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->enum('discount_type', ['percentage', 'fixed'])
                ->nullable()
                ->after('amount');
            $table->decimal('discount_value', 10, 2)->nullable()->default(0)
                ->after('discount_type');
            $table->decimal('discount_amount', 10, 2)->nullable()->default(0)
                ->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_amount']);
        });
    }
};
