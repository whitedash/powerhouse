<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads — prospects in the sales pipeline that have NOT yet
 * converted to customers. They live in their own table so:
 *
 *  - The customer index/list never accidentally includes a
 *    half-qualified lead. The `whereNull('customer_id')` cross
 *    check is the only thing standing between sales and ops
 *    seeing each other's mid-flight records.
 *
 *  - Conversion is explicit: LeadController::convert() creates
 *    the Customer + primary Contact, migrates tasks/notes, then
 *    stamps the lead with customer_id + converted_at. Once
 *    stamped the lead drops out of the kanban.
 *
 * customer_id is SET NULL on delete so archiving a converted
 * customer doesn't take their lead history with it.
 * assigned_to is SET NULL because losing the owner shouldn't
 * orphan the lead — the next staffer can re-claim it.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            // Identity — only first_name is required; the rest get
            // filled in as the conversation progresses.
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('company')->nullable();
            $table->string('job_title')->nullable();

            // Pipeline. won + lost are terminal columns shown
            // collapsed by default in the kanban; converted leads
            // (customer_id NOT NULL) are filtered out of every
            // index query and don't appear at all.
            $table->enum('status', [
                'new', 'contacted', 'qualified', 'proposal',
                'negotiation', 'won', 'lost', 'unresponsive',
            ])->default('new');

            // Source tracking. Mirrors the customer
            // acquisition_channel set so converted leads can
            // preserve their origin without translation.
            $table->enum('source', [
                'manual', 'landing_page', 'facebook', 'google',
                'referral', 'email', 'phone', 'event',
                'word_of_mouth', 'other',
            ])->default('manual');
            $table->string('source_detail')->nullable();

            $table->foreignId('assigned_to')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->decimal('estimated_value', 10, 2)->nullable();
            $table->text('notes')->nullable();

            // Conversion link. customer_id is the marker — when
            // present, the lead has converted and is excluded
            // from the pipeline.
            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->text('lost_reason')->nullable();

            // Form/webhook origin — no FK yet, the forms table
            // arrives in Sprint 3.
            $table->unsignedBigInteger('form_submission_id')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'assigned_to']);
            $table->index(['source', 'created_at']);
            $table->index('customer_id');
            $table->index(['assigned_to', 'status']);
        });

        // tasks.lead_id was added column-only in PM Sprint 1
        // (the original migration deferred the FK). Add it now
        // so deleting a lead nulls out attached tasks instead of
        // silently breaking the relation.
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('lead_id')
                ->references('id')->on('leads')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });
        Schema::dropIfExists('leads');
    }
};
