<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'placeholder' to the form_fields.type enum.
 *
 * A placeholder field is display-only — informational text rendered inside the
 * form with no respondent input. Its display text lives in the existing `label`
 * column; its field_key is synthesised server-side (placeholder_{sort_order}).
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE form_fields MODIFY type ENUM('text','email','phone','textarea','select','radio','checkbox','number','date','hidden','placeholder') NOT NULL DEFAULT 'text'");
    }

    public function down(): void
    {
        // Demote any placeholder fields before narrowing the enum so the column
        // change can't fail on an out-of-range value.
        DB::statement("UPDATE form_fields SET type = 'text' WHERE type = 'placeholder'");
        DB::statement("ALTER TABLE form_fields MODIFY type ENUM('text','email','phone','textarea','select','radio','checkbox','number','date','hidden') NOT NULL DEFAULT 'text'");
    }
};
