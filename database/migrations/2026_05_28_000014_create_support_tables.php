<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 500);
            $table->enum('status', ['open', 'in_progress', 'awaiting_customer', 'resolved', 'closed'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('sentiment_score', 3, 2)->nullable();
            $table->timestamp('sla_breach_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('priority');
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->enum('sender_type', ['customer', 'staff', 'ai']);
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('body');
            $table->boolean('is_internal_note')->default(false);
            $table->decimal('ai_confidence', 3, 2)->nullable();
            $table->string('ai_model', 100)->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('support_knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('body');
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_knowledge_base');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
