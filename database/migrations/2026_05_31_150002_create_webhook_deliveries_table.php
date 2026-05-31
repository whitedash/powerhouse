<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhook ledger. Every event Powerhouse pushes to a consumer
 * product (Maavelus, MyOrderPad, …) is recorded here with its HMAC
 * signature, delivery status and retry bookkeeping. The WebhookDispatcher
 * writes a row before sending; the DeliverWebhook job (or the retry
 * sweep) updates status/attempts/next_retry_at as it goes.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 100);   // e.g. customer_product.suspended
            $table->string('product_slug', 50);  // maavelus, myorderpad, …
            $table->json('payload');
            $table->string('target_url', 500);
            $table->string('signature', 100);    // HMAC-SHA256 of the payload
            $table->enum('status', ['pending', 'delivered', 'failed', 'abandoned'])
                ->default('pending');
            $table->unsignedInteger('http_status')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['product_slug', 'status'], 'webhook_deliveries_product_status_idx');
            $table->index(['event_type', 'created_at'], 'webhook_deliveries_event_created_idx');
            $table->index(['status', 'next_retry_at'], 'webhook_deliveries_retry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
