<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans widget "intro price then full price" (e.g. a £0/reduced trial for N
 * days, after which the real subscription price takes over — billed by the
 * existing invoices:generate-subscriptions sweep with zero changes).
 *
 * Design (option a): the CATALOG declares the transition on the intro price
 * row; a plan purchase EVALUATES it into a concrete swap date stored on the
 * customer_products row — the same "compute at purchase, store the date"
 * pattern next_billing_date already follows here.
 *
 *   product_plan_prices.intro_swap_price_id  — the full price this intro
 *       transitions to (self-FK). NULL = an ordinary price (today's behaviour).
 *   product_plan_prices.intro_duration_days  — N days the intro lasts.
 *       Set together with intro_swap_price_id; controller-enforced, like the
 *       setup_fee / one_time invariant.
 *
 *   customer_products.intro_swap_at        — computed at purchase (now + N
 *       days); plans:apply-intro-price-swaps flips the price when it is due.
 *   customer_products.intro_swap_price_id  — the target full price, SNAPSHOTTED
 *       at purchase so the swap honours what was sold even if the catalog is
 *       later edited, and so the swap command is a pure customer_products query.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('product_plan_prices', function (Blueprint $table): void {
            $table->foreignId('intro_swap_price_id')->nullable()->after('setup_fee')
                ->constrained('product_plan_prices')->nullOnDelete();
            $table->unsignedSmallInteger('intro_duration_days')->nullable()->after('intro_swap_price_id');
        });

        Schema::table('customer_products', function (Blueprint $table): void {
            $table->date('intro_swap_at')->nullable()->after('next_billing_date');
            $table->foreignId('intro_swap_price_id')->nullable()->after('intro_swap_at')
                ->constrained('product_plan_prices')->nullOnDelete();
            // Mirrors the sweep's (status, auto_invoice, next_billing_date)
            // access shape: the swap command scans due, unswapped rows.
            $table->index(['intro_swap_at'], 'customer_products_intro_swap_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table): void {
            $table->dropIndex('customer_products_intro_swap_idx');
            $table->dropConstrainedForeignId('intro_swap_price_id');
            $table->dropColumn('intro_swap_at');
        });

        Schema::table('product_plan_prices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('intro_swap_price_id');
            $table->dropColumn('intro_duration_days');
        });
    }
};
