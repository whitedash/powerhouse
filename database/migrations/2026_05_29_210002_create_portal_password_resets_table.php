<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone reset-token table for portal users. Mirrors Laravel's
 * own password_reset_tokens but keyed independently so a staff and
 * portal reset cycle can run concurrently for the same email.
 *
 * The token column stores a hash of the random plaintext, not the
 * plaintext itself — same defensive pattern Laravel uses for /users.
 * Pruning is handled by the controller (delete on use; only the
 * latest row for a given email survives a re-request).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('portal_password_resets', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_password_resets');
    }
};
