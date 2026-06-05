<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'create_ticket' to the workflow_actions.action_type ENUM so the new
 * create_ticket workflow action (Phase 3) inserts without truncation. The
 * column is a native MySQL ENUM, so this is a raw MODIFY COLUMN — the app
 * (and the MySQL test DB) target MySQL.
 */
return new class() extends Migration
{
    private const WITH = "'create_lead','update_lead_status','create_task','create_ticket','assign_to_user','add_note','send_notification','add_to_group','webhook_outbound'";

    private const WITHOUT = "'create_lead','update_lead_status','create_task','assign_to_user','add_note','send_notification','add_to_group','webhook_outbound'";

    public function up(): void
    {
        DB::statement('ALTER TABLE workflow_actions MODIFY action_type ENUM('.self::WITH.') NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workflow_actions MODIFY action_type ENUM('.self::WITHOUT.') NOT NULL');
    }
};
