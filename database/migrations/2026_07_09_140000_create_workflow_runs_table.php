<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-run workflow audit ledger. One row per workflow FIRING (not per active
 * workflow considered): the WorkflowEngine accumulates each action's outcome
 * and timing in memory during the run, then writes ONE row here in the
 * per-workflow finally — OUTSIDE the per-workflow transaction — so a failed
 * run (an action throws, its transaction rolls back) STILL leaves a
 * status=failed record with the error. That durability is the whole point:
 * the pre-existing workflow.executed activity_log row was written inside the
 * run transaction and so vanished on failure, leaving failed runs invisible.
 *
 * Shape mirrors webhook_deliveries (status-bearing, structured, append-only).
 * Append-only like activity_log: created_at only, no updated_at, no soft
 * deletes. workflow_id is nullOnDelete: purging a workflow (a deliberate hard
 * delete) leaves its run history behind as ORPHANED audit rows (workflow_id
 * NULL) rather than deleting the record of what fired — an audit trail should
 * outlive the entity it audits.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table): void {
            $table->id();
            // Nullable + nullOnDelete: a hard-deleted workflow orphans (does not
            // delete) its run history — the audit trail survives the workflow.
            $table->foreignId('workflow_id')->nullable()->constrained('workflows')->nullOnDelete();
            // Stored as a string, not the workflows.trigger_type ENUM: the set
            // of trigger types is widening (four internal points incoming) and
            // the ledger should not need a migration to record a new one.
            $table->string('trigger_type', 50);
            $table->unsignedBigInteger('trigger_entity_id')->nullable();
            $table->enum('status', ['succeeded', 'failed', 'skipped']);
            // The throwing action's message on a failed run; the guard/skip
            // reason on a workflow-level skip. Null on a clean run.
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // Useful ids resolved by the run (lead_id, ticket_id, customer_id).
            $table->json('context_summary')->nullable();
            // Per-action outcomes: [{action_id, action_type, sort_order,
            // outcome: ran|skipped|failed, skip_reason, error, duration_ms}].
            // Null for a workflow-level skip (no actions were considered).
            $table->json('actions')->nullable();
            // Append-only: created_at only, no updated_at (activity_log's convention).
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workflow_id', 'created_at'], 'workflow_runs_workflow_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
