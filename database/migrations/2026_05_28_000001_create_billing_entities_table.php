<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_entities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name');
            $table->string('company_number')->nullable();
            $table->string('vat_number')->nullable();
            $table->json('address')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('sort_code', 10)->nullable();
            $table->string('account_number', 20)->nullable();
            $table->string('account_name')->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->string('postmark_sender_email');
            $table->string('postmark_sender_name');
            $table->string('postmark_domain')->nullable();
            $table->string('qbo_realm_id')->nullable();
            $table->text('qbo_access_token')->nullable();
            $table->text('qbo_refresh_token')->nullable();
            $table->timestamp('qbo_token_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_entities');
    }
};
