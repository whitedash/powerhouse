<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Top-level table for the project management feature.
 *
 * A project groups milestones, tasks, members and time entries. It can
 * optionally hang off a customer (most do; "internal" projects don't).
 * The `colour` is used everywhere — Kanban headers, MyWork strips, the
 * project card grid — so each project is visually distinct at a glance.
 *
 * `archived_at` is the soft-delete signal. We don't use Laravel's
 * SoftDeletes trait because time entries and tasks must remain visible
 * to staff after a project is archived; archiving is a status, not a
 * removal.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', [
                'planning', 'active', 'on_hold', 'completed', 'cancelled',
            ])->default('planning');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])
                ->default('medium');
            $table->string('colour', 7)->default('#3B82F6');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('budget', 10, 2)->nullable();
            $table->decimal('hourly_rate', 8, 2)->nullable();
            $table->foreignId('project_lead')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['due_date', 'status']);
            $table->index(['status', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
