<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user Google Calendar connection. Each staff member connects
 * their own Google account; the OAuth tokens are stored encrypted via
 * the `encrypted` cast on the User model (so the raw access/refresh
 * tokens never sit in plaintext at rest). google_calendar_id picks
 * which calendar to sync to — null means 'primary'.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // TEXT (not string) — the encrypted ciphertext is far longer
            // than the raw token and overflows a 255 varchar.
            $table->text('google_access_token')->nullable()->after('notification_preferences');
            $table->text('google_refresh_token')->nullable()->after('google_access_token');
            $table->timestamp('google_token_expires_at')->nullable()->after('google_refresh_token');
            $table->string('google_calendar_id')->nullable()->after('google_token_expires_at');
            $table->boolean('google_sync_enabled')->default(false)->after('google_calendar_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'google_access_token',
                'google_refresh_token',
                'google_token_expires_at',
                'google_calendar_id',
                'google_sync_enabled',
            ]);
        });
    }
};
