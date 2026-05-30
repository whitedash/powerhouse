<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proposal line items. Mirrors invoice_lines so the convert-to-
 * contract flow + the public PDF render can reuse the same
 * discount/amount maths. amount is post-discount; the discount
 * fields live alongside for audit so we can later answer
 * "why did the £20 line bill at £18?" without recomputing.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')
                ->constrained('proposals')->cascadeOnDelete();
            $table->string('description', 500);
            $table->text('note')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('amount', 10, 2)->default(0);
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->foreignId('product_id')->nullable()
                ->constrained('products')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()
                ->constrained('product_plans')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['proposal_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_lines');
    }
};
