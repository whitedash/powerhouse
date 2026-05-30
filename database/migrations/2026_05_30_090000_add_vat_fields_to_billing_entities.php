<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VAT switch + default rate per billing entity.
 *
 * Some Whitedash entities sit below the £90k threshold and shouldn't
 * be charging VAT yet; others are registered and default to 20%.
 * Without a per-entity flag the proposal/invoice flow had to ask the
 * operator on every document, which is error-prone. Backfill turns
 * the flag off for any entity that has no VAT number — the safe
 * assumption when the data is silent.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('billing_entities', function (Blueprint $table) {
            $table->decimal('default_vat_rate', 5, 2)->default(20.00)
                ->after('vat_number');
            $table->boolean('vat_registered')->default(true)
                ->after('default_vat_rate');
        });

        // Backfill: any entity that never had a VAT number gets
        // vat_registered=false. Avoids "we're a sole trader but
        // the system still adds 20% by default" land-mines.
        DB::statement('UPDATE billing_entities SET vat_registered = 0 WHERE vat_number IS NULL');
    }

    public function down(): void
    {
        Schema::table('billing_entities', function (Blueprint $table) {
            $table->dropColumn(['default_vat_rate', 'vat_registered']);
        });
    }
};
