<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product ↔ Supplier cost mapping + the remaining QBO alignment columns.
 *
 * product_suppliers carries the per-product underlying cost so margin
 * (plan revenue − supplier cost) can be reported. A composite primary
 * key (product_id, supplier_id) enforces "one cost line per supplier per
 * product" at the DB level — the controller's duplicate check is the
 * friendly guard, this is the backstop.
 *
 * qbo_item_id (products) and qbo_customer_id (customers) round out the
 * QBO field set begun in the suppliers + expenses migrations.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->constrained('products')->cascadeOnDelete();
            $table->foreignId('supplier_id')
                ->constrained('suppliers')->cascadeOnDelete();
            $table->decimal('cost_per_unit', 10, 2)->default(0);
            $table->enum('billing_interval', [
                'monthly', 'quarterly', 'annually', 'one_time',
            ])->default('monthly');
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->primary(['product_id', 'supplier_id']);
            $table->index('supplier_id', 'product_suppliers_supplier_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('qbo_item_id', 100)->nullable()->unique()->after('sort_order');
        });

        if (! Schema::hasColumn('customers', 'qbo_customer_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('qbo_customer_id', 100)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_suppliers');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('qbo_item_id');
        });

        if (Schema::hasColumn('customers', 'qbo_customer_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('qbo_customer_id');
            });
        }
    }
};
