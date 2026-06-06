<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * forms.theme_id — optional link to a form_themes row.
 *
 * Nullable + nullOnDelete: NULL means the form renders with the default
 * tokens (today's hardcoded look), and deleting a theme simply detaches
 * its forms (they fall back to the default) rather than cascading.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->foreignId('theme_id')->nullable()->after('status')
                ->constrained('form_themes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropForeign(['theme_id']);
            $table->dropColumn('theme_id');
        });
    }
};
