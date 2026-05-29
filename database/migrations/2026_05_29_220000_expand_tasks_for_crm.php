<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand the tasks table into a proper CRM activity model.
 *
 *  - type: distinguishes a phone call from a meeting from a generic
 *    todo. Defaults to 'task' so existing rows keep behaving the way
 *    the rest of the app assumes.
 *  - description: long-form details / call notes / meeting agenda.
 *  - priority: low/medium/high for sorting + UI signal.
 *  - contact_id: which Contact this activity involves (call recipient,
 *    meeting attendee). SET NULL on contact delete so the activity
 *    survives a clean-up of stale contacts.
 *  - due_at: replaces due_date as the canonical schedule field. Old
 *    rows are backfilled with the existing date at 09:00 local time —
 *    a sensible morning slot that doesn't claim to know more than the
 *    original data did.
 *  - outcome: what actually happened (used on completion).
 *  - duration_minutes: for calls + meetings.
 *  - is_pinned: surface important notes/activities at the top of the
 *    customer timeline.
 *
 * completed_at already existed pre-migration, so this only writes
 * to it for completed rows that didn't have a timestamp set.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->enum('type', ['task', 'call', 'email', 'meeting', 'note'])
                ->default('task')
                ->after('title');

            $table->text('description')->nullable()->after('type');

            $table->enum('priority', ['low', 'medium', 'high'])
                ->default('medium')
                ->after('description');

            $table->foreignId('contact_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('contacts')
                ->nullOnDelete();

            $table->timestamp('due_at')->nullable()->after('due_date');
            $table->text('outcome')->nullable()->after('completed_at');
            $table->unsignedInteger('duration_minutes')->nullable()->after('outcome');
            $table->boolean('is_pinned')->default(false)->after('duration_minutes');

            $table->index(['customer_id', 'is_pinned', 'due_at']);
        });

        // Backfill due_at from due_date — preserves the existing schedule
        // while we shift the canonical column over. 09:00 is the "I have
        // no better information" default.
        DB::statement("
            UPDATE tasks
            SET due_at = TIMESTAMP(due_date, '09:00:00')
            WHERE due_date IS NOT NULL AND due_at IS NULL
        ");

        // Any task already marked complete with no completed_at timestamp
        // gets one inferred from updated_at, which is the closest signal
        // we have for when the flip happened.
        DB::statement("
            UPDATE tasks
            SET completed_at = updated_at
            WHERE status = 'complete' AND completed_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropIndex(['customer_id', 'is_pinned', 'due_at']);
            $table->dropColumn([
                'type', 'description', 'priority', 'contact_id',
                'due_at', 'outcome', 'duration_minutes', 'is_pinned',
            ]);
        });
    }
};
