<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_entity_id')->nullable()->constrained('billing_entities')->nullOnDelete();
            $table->string('plan', 100)->nullable();
            $table->decimal('price_monthly', 10, 2)->nullable();
            $table->enum('status', ['active', 'trial', 'suspended', 'cancelled'])->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('oauth_client_id')->nullable();
            $table->unsignedBigInteger('wp_user_id')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'product_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_products');
    }
};
