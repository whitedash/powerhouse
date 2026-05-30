<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a product to a default billing entity.
 *
 * Two entities can sell two slightly different things — POS hardware
 * from one, services from the other — and the operator currently
 * picks the entity manually each time. Setting a per-product default
 * makes "new invoice with this product" pick the right entity
 * without thinking. The column is nullable because most existing
 * products are universal (sold under either entity) and shouldn't
 * be retroactively constrained.
 *
 * ON DELETE SET NULL because a billing entity is high-level state —
 * deleting one (rare) shouldn't take down every product attached.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('billing_entity_id')->nullable()->after('description')
                ->constrained('billing_entities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['billing_entity_id']);
            $table->dropColumn('billing_entity_id');
        });
    }
};
