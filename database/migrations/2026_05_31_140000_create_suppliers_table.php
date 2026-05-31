<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers — the vendors behind our costs. Every expense and every
 * product cost line can hang off a supplier so the books reconcile to
 * a single payee record. The qbo_* columns are deliberately seeded now
 * (nullable / not_synced) so a future QuickBooks Online sync sprint can
 * populate them without another migration.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', [
                'software', 'hosting', 'marketing', 'domain_registrar',
                'finance', 'utilities', 'professional_services', 'other',
            ])->default('other');

            // Contact
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website', 500)->nullable();

            // Billing details
            $table->text('address')->nullable();
            // Our account reference with them.
            $table->string('account_number', 100)->nullable();
            // e.g. "Net 30", "Monthly direct debit".
            $table->string('payment_terms', 100)->nullable();

            // Defaults — auto-fill when creating an expense. Category
            // mirrors the expenses.category enum values (validated in
            // the controller, not at the DB level, so the two enums can
            // evolve independently).
            $table->string('default_expense_category', 50)->nullable();
            $table->decimal('default_vat_rate', 5, 2)->default(20.00);

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            // QuickBooks alignment — populated by a future sync.
            $table->string('qbo_vendor_id', 100)->nullable()->unique();
            $table->enum('qbo_sync_status', [
                'not_synced', 'synced', 'error', 'excluded',
            ])->default('not_synced');
            $table->timestamp('qbo_synced_at')->nullable();
            // Last error message returned by QBO.
            $table->text('qbo_sync_error')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active'], 'suppliers_type_active_idx');
            $table->index('name', 'suppliers_name_idx');
            // qbo_vendor_id already has a unique index from ->unique().
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
