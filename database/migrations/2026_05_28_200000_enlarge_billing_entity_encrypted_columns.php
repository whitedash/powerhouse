<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypted casts (sort_code, account_number, account_name) blow up text to
 * 200+ bytes after AES + base64 + IV + tag, so the original VARCHAR limits
 * are too small. Move them to TEXT.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('billing_entities', function (Blueprint $table) {
            $table->text('sort_code')->nullable()->change();
            $table->text('account_number')->nullable()->change();
            $table->text('account_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::statement('UPDATE billing_entities SET sort_code = NULL, account_number = NULL, account_name = NULL');

        Schema::table('billing_entities', function (Blueprint $table) {
            $table->string('sort_code', 10)->nullable()->change();
            $table->string('account_number', 20)->nullable()->change();
            $table->string('account_name')->nullable()->change();
        });
    }
};
