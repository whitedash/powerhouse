<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing P1: saved (vaulted) cards. Only SAFE card metadata is stored — brand
 * / last4 / expiry. NEVER the PAN, CVC, or any secret; the card itself lives in
 * Stripe and is referenced by stripe_payment_method_id.
 *
 * stripe_customer_id is denormalised here (matches the Stripe object graph: a
 * PaymentMethod is attached to a Stripe Customer). A future per-entity/account
 * split adds a billing_entity_id column (additive).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_customer_id', 100);
            $table->string('stripe_payment_method_id', 100)->unique();
            // Safe display metadata only.
            $table->string('brand', 40)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->boolean('is_default')->default(false);
            // active = usable; removed = detached by the customer/staff (kept for
            // the audit trail rather than hard-deleted).
            $table->enum('status', ['active', 'removed'])->default('active');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
