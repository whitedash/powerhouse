<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an invoice line link back to the product (and optionally the
 * plan) it bills for. Useful for two surfaces: the invoice row badge
 * that tells you "this is a Maavelus charge at a glance," and the
 * upcoming reporting layer that will roll up revenue per product
 * without parsing the description string.
 *
 * Both columns are nullable + SET NULL: deleting a product shouldn't
 * delete historical invoice rows, and the line still carries its
 * description so the invoice continues to make sense.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->foreignId('product_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('products')
                ->nullOnDelete();
            $table->foreignId('plan_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['product_id']);
            $table->dropColumn(['plan_id', 'product_id']);
        });
    }
};
