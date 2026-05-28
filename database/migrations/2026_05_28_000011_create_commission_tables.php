<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->nullable()->constrained('referrers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['one_off_pct', 'recurring_tiered', 'hybrid']);
            $table->json('config');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['referrer_id', 'product_id']);
        });

        Schema::create('commission_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('referrers')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rule_id')->constrained('commission_rules')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->enum('trigger_type', ['onboarding', 'invoice_paid', 'monthly_recurring']);
            $table->decimal('gross_amount', 10, 2)->default(0);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->enum('status', ['pending', 'approved', 'paid', 'voided'])->default('pending');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('voided_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_ledger');
        Schema::dropIfExists('commission_rules');
    }
};
