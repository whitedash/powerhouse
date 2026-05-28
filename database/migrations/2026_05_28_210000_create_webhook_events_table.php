<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50);
            $table->string('event_id', 255);
            $table->string('event_type', 100);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['source', 'event_id']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
