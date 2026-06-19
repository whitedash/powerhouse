<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * form_fields.content — rich-text body for display-only 'placeholder' fields.
 *
 * Placeholder fields previously stored their display text in `label`
 * (VARCHAR 255), which truncated multi-line / rich content and threw
 * SQLSTATE[22001]. Their text now lives in a dedicated TEXT column; `label`
 * stays VARCHAR(255) and continues to hold the label for every other type.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->text('content')->nullable()->after('label');
        });

        // Move any existing placeholder text out of the (short) label column.
        DB::statement("UPDATE form_fields SET content = label, label = '' WHERE type = 'placeholder'");
    }

    public function down(): void
    {
        // Best-effort restore: fold content back into label, truncated to 255.
        DB::statement("UPDATE form_fields SET label = SUBSTRING(content, 1, 255) WHERE type = 'placeholder' AND content IS NOT NULL");

        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropColumn('content');
        });
    }
};
