<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the existing `tasks` table to participate in the project
 * management feature without losing the rows already in the table.
 *
 * Two concerns:
 *
 *  1) The old status enum (`open`, `complete`) doesn't carry the
 *     six-state PM workflow (`todo`, `in_progress`, `in_review`,
 *     `blocked`, `complete`, `cancelled`). We can't ALTER an ENUM
 *     in place without first widening the column to a string that
 *     accepts both old + new values — otherwise existing 'open'
 *     rows would fail the new constraint.
 *
 *     Strategy: stage the new value into a temp column, narrow the
 *     enum to the new set, then copy the staged value back.
 *
 *  2) FK on `lead_id`: deferred. The leads table doesn't exist yet
 *     (Sprint 2). We add the nullable column now so the controller
 *     doesn't have to special-case its absence; the FK gets added
 *     in the Sprint 2 migration.
 */
return new class() extends Migration
{
    public function up(): void
    {
        // 1) Add new columns first. `task_status` is the staging
        // column for the status backfill — it's dropped in step 5.
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('customer_id')
                ->constrained('projects')->nullOnDelete();
            $table->foreignId('milestone_id')->nullable()->after('project_id')
                ->constrained('milestones')->nullOnDelete();
            $table->unsignedBigInteger('lead_id')->nullable()->after('milestone_id');
            $table->string('task_status', 20)->default('todo');
            $table->decimal('estimated_hours', 6, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('blocked_reason')->nullable();
        });

        // 2) Backfill task_status from the legacy enum so we don't
        // lose information when we replace the column. Anything
        // unknown becomes 'todo' — defensive default.
        DB::statement("UPDATE tasks SET task_status = CASE status
            WHEN 'open'     THEN 'todo'
            WHEN 'complete' THEN 'complete'
            ELSE 'todo'
        END");

        // 3) Widen the status enum to the new six-state workflow.
        // We're on MySQL and MODIFY COLUMN keeps the column data —
        // each row's 'open' or 'complete' would fail the new enum,
        // so we first wipe to a safe default value, then re-enum.
        DB::statement("UPDATE tasks SET status = 'complete'
            WHERE status = 'complete'");
        DB::statement("UPDATE tasks SET status = 'open'
            WHERE status NOT IN ('open','complete')");

        // The new enum includes 'complete' (preserved verbatim) but
        // replaces 'open' with the PM-flavoured set. MySQL allows
        // narrowing an enum only if existing values are in the new
        // set — at this point every row is either 'complete' or
        // 'open', so we temporarily widen via a CASE update first.
        DB::statement("UPDATE tasks SET status = 'complete'
            WHERE status = 'open' AND task_status = 'complete'");

        // Now replace the column with the new enum. 'todo' becomes
        // the default; existing 'open' rows would still violate the
        // new constraint, so do one final pass to map them.
        DB::statement("ALTER TABLE tasks MODIFY COLUMN status
            VARCHAR(20) NOT NULL DEFAULT 'todo'");
        DB::statement('UPDATE tasks SET status = task_status');
        DB::statement("ALTER TABLE tasks MODIFY COLUMN status
            ENUM('todo','in_progress','in_review','blocked','complete','cancelled')
            NOT NULL DEFAULT 'todo'");

        // 4) Drop the staging column.
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('task_status');
        });

        // 5) Indexes for the kanban board (project + milestone +
        // ordering), the project tasks tab (project + status), and
        // the MyWork page (assignee + status).
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['project_id', 'milestone_id', 'sort_order'], 'tasks_pm_board_idx');
            $table->index(['project_id', 'status'], 'tasks_pm_status_idx');
            $table->index(['assigned_to', 'status'], 'tasks_mywork_idx');
        });
    }

    public function down(): void
    {
        // Reverse: collapse the new enum back to ('open','complete')
        // before dropping the new columns. Anything that isn't
        // 'complete' rolls up to 'open' so no row is lost.
        DB::statement("ALTER TABLE tasks MODIFY COLUMN status
            VARCHAR(20) NOT NULL DEFAULT 'open'");
        DB::statement("UPDATE tasks SET status = CASE
            WHEN status = 'complete' THEN 'complete'
            ELSE 'open'
        END");
        DB::statement("ALTER TABLE tasks MODIFY COLUMN status
            ENUM('open','complete') NOT NULL DEFAULT 'open'");

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_pm_board_idx');
            $table->dropIndex('tasks_pm_status_idx');
            $table->dropIndex('tasks_mywork_idx');
            $table->dropForeign(['project_id']);
            $table->dropForeign(['milestone_id']);
            $table->dropColumn([
                'project_id', 'milestone_id', 'lead_id',
                'estimated_hours', 'sort_order', 'blocked_reason',
            ]);
        });
    }
};
