<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses — the cost side of the books. Every entry is a discrete
 * line: a software subscription, a hosting bill, a referral
 * commission paid out. We deliberately model `total` as a stored
 * column (vs. accessor) so per-category reports run against a
 * single SUM without re-computing VAT in PHP.
 *
 * The two FK shortcuts are pragmatic optimisations:
 *  - project_id lets the project Time tab roll project expenses
 *    into its summary.
 *  - commission_ledger_id is the audit link back to the originating
 *    referrer payout — set automatically when a CommissionLedger
 *    row hits 'paid'. Without it, the operator would have to
 *    manually reconcile each commission against the books.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->enum('category', [
                'referral_commission', 'software', 'hosting', 'travel',
                'office', 'marketing', 'advertising', 'equipment', 'other',
            ])->default('other');
            $table->string('description');
            $table->string('supplier')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->date('expense_date');
            $table->enum('status', ['pending', 'approved', 'paid'])
                ->default('pending');
            $table->boolean('is_reimbursable')->default(false);
            $table->string('receipt_path', 500)->nullable();
            $table->string('receipt_original_name')->nullable();
            $table->foreignId('project_id')->nullable()
                ->constrained('projects')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();
            $table->foreignId('commission_ledger_id')->nullable()
                ->constrained('commission_ledger')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')
                ->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['category', 'status', 'expense_date'], 'expenses_filter_idx');
            $table->index('commission_ledger_id', 'expenses_commission_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
