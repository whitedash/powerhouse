<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time entries log work against a task. We persist `project_id`
 * alongside `task_id` even though it's derivable — the time tab
 * aggregates by project across thousands of entries, and a
 * pre-joined query against (project_id, is_billable, invoice_id)
 * is dramatically cheaper than walking through tasks every time.
 * Backend code is responsible for keeping it in sync with the
 * parent task's project_id.
 *
 * `minutes` is the storage unit; controllers and the UI convert
 * to hours for display. Storing minutes avoids float drift across
 * "1.5h + 0.25h" summations.
 *
 * `invoice_id` + `invoice_line_id` are set when the entry has been
 * rolled into a billable invoice. Nullable + SET NULL on delete so a
 * deleted invoice unbills the time entries rather than wiping them.
 * RESTRICT on user_id because deleting a user with logged time
 * would leave billable hours unaccounted for; admin should reassign
 * first.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')
                ->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('project_id')
                ->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('minutes');
            $table->text('description')->nullable();
            $table->date('logged_at');
            $table->boolean('is_billable')->default(true);
            $table->decimal('hourly_rate', 8, 2)->nullable();
            $table->foreignId('invoice_line_id')->nullable()
                ->constrained('invoice_lines')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()
                ->constrained('invoices')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'is_billable', 'invoice_id']);
            $table->index('task_id');
            $table->index(['user_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
