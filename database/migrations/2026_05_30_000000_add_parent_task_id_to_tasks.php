<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-referential parent_task_id on tasks for the activity detail
 * page's "linked tasks" / sub-task pattern. Nullable + SET NULL so a
 * deleted parent doesn't cascade-wipe its children — orphaning a
 * child task is the safer default than silently losing it.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('parent_task_id')
                ->nullable()
                ->after('contact_id')
                ->constrained('tasks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['parent_task_id']);
            $table->dropColumn('parent_task_id');
        });
    }
};
