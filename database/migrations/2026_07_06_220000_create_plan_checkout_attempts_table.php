<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans widget: track checkout attempts that started but never settled,
 * WITHOUT reversing the "no Company/Contact/Person/Invoice writes at
 * checkout-init" guarantee — this purpose-built table is the only thing
 * the init endpoint writes. The webhook marks the row completed on
 * settlement; the plans:reconcile-abandoned-checkouts sweep flips stale
 * pending rows to abandoned (default window 24h = Stripe Checkout's own
 * session expiry) and alerts staff once per attempt.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('plan_checkout_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_price_id')->nullable()
                ->constrained('product_plan_prices')->nullOnDelete();
            $table->string('purchaser_name');
            $table->string('purchaser_email');
            $table->string('stripe_checkout_session_id', 100)->unique();
            $table->enum('status', ['pending', 'completed', 'abandoned'])->default('pending');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamps();

            // The reconciler sweeps "pending older than the window".
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_checkout_attempts');
    }
};
