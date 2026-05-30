<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring-invoice columns.
 *
 * is_recurring + the three recurring_* fields drive the
 * invoices:generate-recurring artisan command, which clones the
 * invoice into a draft child on each due date. parent_invoice_id
 * tracks lineage from the child side so the detail page can show
 * "this was generated from INV-NNNN".
 *
 * Index over (is_recurring, recurring_next_date) keeps the scheduled
 * sweep cheap — it's a daily job, but a sequential scan of every
 * invoice row to find the recurring ones would scale badly.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->boolean('is_recurring')
                ->default(false)
                ->after('status');
            $table->unsignedTinyInteger('recurring_interval_count')
                ->nullable()
                ->after('is_recurring');
            $table->enum('recurring_interval_unit', ['week', 'month', 'year'])
                ->nullable()
                ->after('recurring_interval_count');
            $table->date('recurring_next_date')
                ->nullable()
                ->after('recurring_interval_unit');
            $table->date('recurring_ends_at')
                ->nullable()
                ->after('recurring_next_date');
            $table->foreignId('parent_invoice_id')
                ->nullable()
                ->after('recurring_ends_at')
                ->constrained('invoices')
                ->nullOnDelete();

            $table->index(['is_recurring', 'recurring_next_date'], 'invoices_recurring_sweep_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_recurring_sweep_idx');
            $table->dropForeign(['parent_invoice_id']);
            $table->dropColumn([
                'is_recurring',
                'recurring_interval_count',
                'recurring_interval_unit',
                'recurring_next_date',
                'recurring_ends_at',
                'parent_invoice_id',
            ]);
        });
    }
};
