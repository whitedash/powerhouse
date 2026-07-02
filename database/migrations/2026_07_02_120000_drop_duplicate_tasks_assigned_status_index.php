<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops tasks_assigned_to_status_index — an exact duplicate of
 * tasks_mywork_idx (both cover (assigned_to, status)). The original
 * unnamed index shipped with the create-tasks migration
 * (2026_05_28_000015, line 31); the PM sprint re-added the same pair
 * as tasks_mywork_idx (2026_05_30_070004, line 94). Surfaced by the
 * 2026-07 SCHEMA.md catch-up's live-schema verification. Keeper is
 * tasks_mywork_idx — the one SCHEMA.md documents — which also takes
 * over as the backing index for the tasks_assigned_to_foreign FK
 * (InnoDB permits the drop because an alternative index with
 * assigned_to leading exists; without it the ALTER would refuse).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_assigned_to_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['assigned_to', 'status'], 'tasks_assigned_to_status_index');
        });
    }
};
