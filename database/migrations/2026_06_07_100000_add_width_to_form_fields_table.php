<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_fields.width — per-field layout width for multi-column forms.
 *
 * String (not enum column) so adding a future width never needs a schema
 * migration; the App\Enums\FieldWidth enum is the source of truth and the
 * cast. Defaults to 'full' so every existing field keeps today's
 * single-column stack — zero visual change on deploy.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->string('width', 16)->default('full')->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropColumn('width');
        });
    }
};
