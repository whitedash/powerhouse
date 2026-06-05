<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-class link from a task to the support ticket it was spawned
 * from. TicketIntakeService creates a triage Task per inbound ticket;
 * until now that link lived only in the task title text. A nullable FK
 * (nullOnDelete) makes it a real relation without disturbing the vast
 * majority of tasks that have nothing to do with support — and existing
 * triage tasks simply keep ticket_id = null and fall back to title.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('ticket_id')
                ->nullable()
                ->after('lead_id')
                ->constrained('support_tickets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
            $table->dropColumn('ticket_id');
        });
    }
};
