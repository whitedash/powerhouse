<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestones group tasks inside a project into ordered phases. The
 * board view in milestone mode renders one column per milestone; the
 * default kanban draws from the (project_id, sort_order) index.
 *
 * Cascade-delete is fine here: a milestone has no value outside its
 * project, and tasks losing their milestone get their FK nulled by
 * the tasks migration (set_null on ON DELETE), so no task is lost.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')
                ->constrained('projects')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed'])
                ->default('pending');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestones');
    }
};
