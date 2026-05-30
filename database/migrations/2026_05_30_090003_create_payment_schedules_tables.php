<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment schedules — break a proposal/project total into
 * installments, each triggering an invoice on a different
 * condition (immediately, on a date, when a milestone completes,
 * or manually).
 *
 * Two tables together because they're never queried apart:
 *  - payment_schedules holds the header + customer/entity context.
 *  - payment_schedule_items holds the individual installments,
 *    each one pointing at the invoice it spawned (once spawned).
 *
 * milestone_id (SET NULL) lets the operator delete a milestone
 * without nuking the schedule row; the item just becomes orphan
 * and can be re-attached or invoiced manually.
 *
 * Indexes target the three lookup paths:
 *  - schedule_id + sort_order — render the items list.
 *  - milestone_id + status — milestone-completion hook.
 *  - trigger_type + trigger_date + status — date-driven cron
 *    (not built in this sprint but the index is cheap now).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('proposal_id')->nullable()
                ->constrained('proposals')->nullOnDelete();
            $table->foreignId('project_id')->nullable()
                ->constrained('projects')->nullOnDelete();
            $table->foreignId('customer_id')
                ->constrained('customers')->restrictOnDelete();
            $table->foreignId('billing_entity_id')->nullable()
                ->constrained('billing_entities')->nullOnDelete();
            $table->decimal('total', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')
                ->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')
                ->constrained('payment_schedules')->cascadeOnDelete();
            $table->string('label');
            // percentage is what the operator typed; amount is the
            // computed £ figure. We keep both so editing the schedule
            // total can re-derive amounts without losing intent.
            $table->decimal('percentage', 5, 2)->nullable();
            $table->decimal('amount', 10, 2);
            $table->enum('trigger_type', ['immediate', 'on_date', 'on_milestone', 'manual'])
                ->default('manual');
            $table->date('trigger_date')->nullable();
            $table->foreignId('milestone_id')->nullable()
                ->constrained('milestones')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()
                ->constrained('invoices')->nullOnDelete();
            $table->enum('status', ['pending', 'invoiced', 'paid'])
                ->default('pending');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['schedule_id', 'sort_order']);
            $table->index(['milestone_id', 'status']);
            $table->index(['trigger_type', 'trigger_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_schedule_items');
        Schema::dropIfExists('payment_schedules');
    }
};
