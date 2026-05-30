<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflows — generic "if X happens, do Y, Z" automation.
 *
 * A workflow has a trigger_type + trigger_config and a list of
 * workflow_actions executed in sort_order. WorkflowEngine::trigger()
 * is the single entry point — it finds workflows matching the event,
 * runs each one's actions inside a per-workflow transaction, and
 * stamps run_count + last_run_at.
 *
 * The first trigger source is form_submitted (Forms sprint), but the
 * enum carries hooks for lead_created / lead_status_changed /
 * webhook_received / manual so future code paths can call
 * WorkflowEngine::trigger() without a new migration.
 *
 * trigger_config and action config are JSON blobs so an action's
 * shape (which template, which user to assign to) can change without
 * a schema change. The engine reads them defensively (?? defaults
 * everywhere) so a partially-configured workflow degrades to a
 * no-op rather than a 500.
 *
 * created_by is RESTRICT because a workflow with no owner is a
 * support-ticket landmine — block the user delete instead.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->enum('trigger_type', [
                'form_submitted',
                'webhook_received',
                'lead_created',
                'lead_status_changed',
                'manual',
            ]);
            // Trigger-type-specific filter, e.g.:
            //   form_submitted        => {"form_id": 4}
            //   lead_status_changed   => {"to": "qualified"}
            //   webhook_received      => {"source": "mailchimp"}
            $table->json('trigger_config')->nullable();

            $table->integer('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['trigger_type', 'is_active']);
        });

        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_id')
                ->constrained('workflows')->cascadeOnDelete();

            $table->enum('action_type', [
                'create_lead',
                'update_lead_status',
                'create_task',
                'assign_to_user',
                'add_note',
                'send_notification',
                'add_to_group',
                'webhook_outbound',
            ]);

            // Action-type-specific config. Shape examples:
            //   create_lead: {"first_name_field":"first_name",
            //                 "email_field":"email","source":"landing_page",
            //                 "assigned_to":1}
            //   create_task: {"title_template":"Follow up with {{first_name}}",
            //                 "type":"call","priority":"high",
            //                 "assigned_to":1,"due_in_days":1}
            //   add_note:    {"content_template":"Lead from {{source}}: {{message}}"}
            $table->json('config');

            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['workflow_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflows');
    }
};
