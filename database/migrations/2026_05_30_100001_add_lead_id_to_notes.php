<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes can now hang off a lead in addition to a customer / task.
 * The lead detail page's notes thread filters on lead_id, and on
 * conversion the lead's notes get re-targeted at the new customer.
 *
 * SET NULL on delete because a deleted lead doesn't necessarily
 * mean the notes should disappear — they may have been useful
 * context that the operator wants to keep on a sibling record.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()
                ->after('customer_id')
                ->constrained('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropColumn('lead_id');
        });
    }
};
