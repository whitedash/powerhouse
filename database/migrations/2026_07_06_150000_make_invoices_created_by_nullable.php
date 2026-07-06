<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans widget (PLANS-WIDGET-DESIGN.md §4): webhook-settled plan purchases
 * create the invoice with no authenticated staff user, so created_by must
 * allow NULL. NULL = system-created — the same convention as
 * payments.created_by and activity_log.user_id. Every existing writer
 * still records a real user; the proposal-accept flow keeps attributing
 * to the proposal's creator.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });
    }
};
