<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'send_email' to the workflow_actions.action_type ENUM so the new
 * send_email workflow action inserts without truncation. The column is a
 * native MySQL ENUM, so this is a raw MODIFY COLUMN — the app (and the MySQL
 * test DB) target MySQL. Mirrors the create_ticket enum migration.
 */
return new class() extends Migration
{
    private const WITH = "'create_lead','update_lead_status','create_task','create_ticket','assign_to_user','add_note','send_notification','add_to_group','webhook_outbound','send_email'";

    private const WITHOUT = "'create_lead','update_lead_status','create_task','create_ticket','assign_to_user','add_note','send_notification','add_to_group','webhook_outbound'";

    public function up(): void
    {
        DB::statement('ALTER TABLE workflow_actions MODIFY action_type ENUM('.self::WITH.') NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workflow_actions MODIFY action_type ENUM('.self::WITHOUT.') NOT NULL');
    }
};
