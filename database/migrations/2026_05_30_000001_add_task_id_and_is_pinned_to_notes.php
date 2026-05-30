<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets notes attach to a specific task in addition to (or instead of)
 * a customer — so the activity detail page can render a thread of
 * notes scoped to the activity. is_pinned floats important notes to
 * the top of the thread.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            // SET NULL on delete keeps the note around for the audit trail
            // even after the parent task is gone — the customer link is
            // the authoritative anchor anyway.
            $table->foreignId('task_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('tasks')
                ->nullOnDelete();
            $table->boolean('is_pinned')
                ->default(false)
                ->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->dropForeign(['task_id']);
            $table->dropColumn(['task_id', 'is_pinned']);
        });
    }
};
