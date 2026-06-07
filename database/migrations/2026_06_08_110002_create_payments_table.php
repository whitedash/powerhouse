<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing P1: the payments ledger. One row per settlement attempt against an
 * invoice. Stood up NOW (before any off-session charging in P2) so every
 * settle — the existing on-session Stripe checkout and manual mark-paid — is
 * recorded from day one. GBP only for now (currency column present so a future
 * multi-currency change is additive).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('gbp');
            // The payment rail / channel.
            $table->enum('rail', ['stripe', 'manual', 'bank', 'other'])->default('stripe');
            $table->string('stripe_payment_intent_id', 100)->nullable();
            $table->enum('status', ['pending', 'succeeded', 'failed'])->default('succeeded');
            $table->timestamp('attempted_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
