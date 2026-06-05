<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency backstop for the invoice-paid commission engine: never credit
 * the same invoice × referrer × product twice (webhook retries + the
 * multi-path settle all flow through StripeService::markInvoicePaid).
 *
 * invoice_id is NULLable; MySQL treats NULLs as distinct in a unique index,
 * so the Maavelus statement rows (invoice_id = NULL) never collide with each
 * other or with invoice-scoped rows.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->unique(
                ['invoice_id', 'referrer_id', 'product_id'],
                'commission_ledger_invoice_referrer_product_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropUnique('commission_ledger_invoice_referrer_product_unique');
        });
    }
};
