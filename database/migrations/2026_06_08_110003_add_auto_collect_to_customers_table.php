<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing P1: per-customer auto-collect intent. When true, P2 will charge the
 * customer's default saved card off-session for due invoices. In P1 this is
 * stored + toggled only — nothing reads it to charge yet.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('auto_collect')->default(false)->after('exempt_reason');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('auto_collect');
        });
    }
};
