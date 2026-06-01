<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar sprint — timed-event + Google-sync columns on tasks.
 *
 * Until now a task only carried a single `due_at` instant, which the
 * MyWork list treats as a day-level deadline. The calendar view needs
 * to distinguish an all-day deadline from a timed event/meeting that
 * has a real start + end, plus a place to hang the Google Calendar
 * event id once a task is mirrored over there.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'start_at')) {
                $table->timestamp('start_at')->nullable()->after('due_at');
            }
            if (! Schema::hasColumn('tasks', 'end_at')) {
                $table->timestamp('end_at')->nullable()->after('start_at');
            }
            if (! Schema::hasColumn('tasks', 'location')) {
                $table->string('location')->nullable()->after('end_at');
            }
            // true  → due_at is a date-only deadline (the legacy shape)
            // false → timed event; start_at/end_at carry the real slot
            if (! Schema::hasColumn('tasks', 'is_all_day')) {
                $table->boolean('is_all_day')->default(true)->after('location');
            }
            if (! Schema::hasColumn('tasks', 'google_event_id')) {
                $table->string('google_event_id')->nullable()->after('is_all_day');
            }
        });

        Schema::table('tasks', function (Blueprint $table): void {
            // Wrapped in hasColumn so a re-run after a partial failure
            // doesn't try to re-index an already-indexed column.
            if (Schema::hasColumn('tasks', 'start_at')) {
                $table->index('start_at');
            }
            if (Schema::hasColumn('tasks', 'google_event_id')) {
                $table->index('google_event_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['start_at']);
            $table->dropIndex(['google_event_id']);
            $table->dropColumn(['start_at', 'end_at', 'location', 'is_all_day', 'google_event_id']);
        });
    }
};
