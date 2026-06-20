<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'datetime' to the form_fields.type enum.
 *
 * A datetime field renders an <input type="datetime-local"> (date + time
 * picker). It is separate from the existing 'date' type, which is unchanged.
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE form_fields MODIFY type ENUM('text','email','phone','textarea','select','radio','checkbox','number','date','hidden','placeholder','datetime') NOT NULL DEFAULT 'text'");
    }

    public function down(): void
    {
        // Demote any datetime fields before narrowing the enum so the column
        // change can't fail on an out-of-range value.
        DB::statement("UPDATE form_fields SET type = 'date' WHERE type = 'datetime'");
        DB::statement("ALTER TABLE form_fields MODIFY type ENUM('text','email','phone','textarea','select','radio','checkbox','number','date','hidden','placeholder') NOT NULL DEFAULT 'text'");
    }
};
