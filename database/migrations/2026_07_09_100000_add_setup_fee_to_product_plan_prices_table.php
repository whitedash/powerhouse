<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans widget — setup fee + recurring (pattern a). A recurring
 * product_plan_prices row can carry an optional one-off setup_fee: the
 * plan purchase charges the FEE immediately and provisions an
 * auto_invoice=true customer_product that the invoices:generate-subscriptions
 * sweep then bills at `price` on cadence.
 *
 * "A one_time price must not carry a setup_fee" is enforced at the
 * validation layer (ProductPlanPriceController), matching SCHEMA.md's
 * existing controller-enforced conventions (one active domain plan per
 * TLD; one active price tier per domain plan) rather than a DB CHECK —
 * Laravel migrations have no first-class CHECK support and the codebase
 * consistently gates these invariants in the request/controller.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('product_plan_prices', function (Blueprint $table): void {
            $table->decimal('setup_fee', 10, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('product_plan_prices', function (Blueprint $table): void {
            $table->dropColumn('setup_fee');
        });
    }
};
