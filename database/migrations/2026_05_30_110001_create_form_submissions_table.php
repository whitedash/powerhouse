<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form submissions — the raw "what landed" record.
 *
 * The submission is written FIRST (status = new) then the
 * WorkflowEngine runs inside the same transaction. If the
 * engine succeeds the status flips to 'processed' and
 * lead_id gets stamped if the create_lead action fired.
 * If anything throws inside the engine the transaction
 * rolls back — there is no half-processed row.
 *
 * Why form_id is RESTRICT on delete: a submission is a piece
 * of business history (a lead came from somewhere). Deleting
 * the form behind it would silently orphan that origin, so
 * we force the operator to soft-delete the form instead —
 * status='inactive' is the supported way to retire a form.
 *
 * lead_id is SET NULL because deleting a lead from the
 * pipeline shouldn't take the submission record with it —
 * the submission stays for audit / debugging the workflow.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('form_id')
                ->constrained('forms')->restrictOnDelete();

            // Everything the submitter posted, minus framework
            // noise (_token, _hp, _method). Stored as JSON so
            // we don't need a schema migration per new field.
            $table->json('data');

            $table->enum('status', ['new', 'processed', 'spam', 'error'])
                ->default('new');

            // IPv6 max length = 45. ip_address + user_agent are
            // recorded for abuse triage; referrer_url is captured
            // for funnel attribution.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referrer_url', 500)->nullable();

            $table->foreignId('lead_id')->nullable()
                ->constrained('leads')->nullOnDelete();

            $table->timestamps();

            $table->index(['form_id', 'status', 'created_at']);
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
