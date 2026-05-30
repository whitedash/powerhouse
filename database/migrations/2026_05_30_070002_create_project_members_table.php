<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot for the many-to-many between projects and users. Composite
 * primary key prevents a user being attached twice; cascade-delete
 * on both sides because the row has no meaning if either parent goes
 * away. The role distinguishes a project lead (one per project, but
 * not enforced here — the constraint sits in app code) from members
 * and read-only viewers.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['lead', 'member', 'viewer'])
                ->default('member');
            $table->timestamp('joined_at')->useCurrent();

            $table->primary(['project_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
    }
};
