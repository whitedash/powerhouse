<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('product_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('product_plans')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedTinyInteger('interval_count')->default(1);
            $table->enum('interval_unit', ['day', 'week', 'month', 'year', 'one_time'])
                ->default('month');
            $table->string('stripe_price_id', 100)->nullable();
            // Optional marketing label like "Best value" / "Most popular".
            $table->string('label', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['plan_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_plan_prices');
    }
};
