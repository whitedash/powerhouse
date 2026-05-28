<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_entity_id')->constrained('billing_entities')->restrictOnDelete();
            $table->enum('type', ['subscription', 'service'])->default('service');
            $table->enum('status', ['draft', 'sent', 'paid', 'overdue', 'void'])->default('draft');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(20);
            $table->decimal('vat_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->date('issue_date');
            $table->date('due_date');
            $table->timestamp('paid_at')->nullable();
            $table->enum('payment_method', ['bank_transfer', 'card', 'direct_debit', 'other'])->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('qbo_invoice_id', 100)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
