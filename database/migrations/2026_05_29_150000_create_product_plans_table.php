<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('product_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->decimal('price_annual', 10, 2)->nullable();
            $table->json('features')->nullable();
            $table->string('stripe_price_id_monthly', 100)->nullable();
            $table->string('stripe_price_id_annual', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Driver index for both the internal plan picker (filtered to
            // active) and the public pricing API (filtered to active +
            // public). sort_order trails so the sort happens in-index.
            $table->index(['product_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_plans');
    }
};
