<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project-level file storage with async malware scanning. A row can be
 * project-level (task_id null) or attached to a task. Files live on the
 * private disk under a UUID name; scan_status tracks the ClamAV pass
 * (pending → clean/infected/error/skipped). uploaded_by is RESTRICT so a
 * user with files can't be hard-deleted out from under the audit trail.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();

            $table->string('filename', 255);
            $table->string('stored_name', 255);
            $table->string('path', 500);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');

            $table->enum('scan_status', ['pending', 'clean', 'infected', 'error', 'skipped'])
                ->default('pending');
            $table->timestamp('scan_completed_at')->nullable();
            $table->text('scan_result')->nullable();

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('description', 255)->nullable();

            $table->timestamps();

            $table->index(['project_id', 'scan_status']);
            $table->index('task_id');
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_files');
    }
};
