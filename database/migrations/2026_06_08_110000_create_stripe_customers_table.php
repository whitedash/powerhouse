<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing P1: the Customer ↔ Stripe-Customer mapping.
 *
 * Kept as its own table (not a bare column on customers) so a future
 * per-billing-entity / per-Stripe-account split is ADDITIVE: add a
 * `billing_entity_id` column and relax the unique key from (customer_id) to
 * (customer_id, billing_entity_id) — one Stripe customer per (customer, account).
 * Today there is a single GBP Stripe account, so one row per customer.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_customer_id', 100)->unique();
            $table->timestamps();

            // One Stripe customer per customer for the single-account world.
            // Future per-entity split: drop this and add a composite unique
            // on (customer_id, billing_entity_id).
            $table->unique('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_customers');
    }
};
