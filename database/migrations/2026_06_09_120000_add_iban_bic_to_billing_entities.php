<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * International bank details for invoices: IBAN + BIC (SWIFT) per billing
 * entity, alongside the existing UK fields (bank_name / account_name /
 * sort_code / account_number). Per-entity — each company keeps its own once
 * the entities split into separate legal companies.
 *
 * Stored as `text` + the model's `encrypted` cast, mirroring the existing
 * encrypted bank columns (sort_code/account_number/account_name) — encrypted
 * at rest, decrypted for display on the invoice/portal. Nullable: entities
 * without international details simply render no IBAN/BIC.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('billing_entities', function (Blueprint $table): void {
            $table->text('iban')->nullable()->after('account_name');
            $table->text('bic')->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('billing_entities', function (Blueprint $table): void {
            $table->dropColumn(['iban', 'bic']);
        });
    }
};
