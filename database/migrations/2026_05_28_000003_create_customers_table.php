<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trading_name')->nullable();
            $table->string('company_number')->nullable();
            $table->string('vat_number')->nullable();
            $table->enum('type', ['restaurant', 'bar', 'bakery', 'cafe', 'venue', 'other'])->default('other');
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('country', 2)->nullable();
            $table->json('billing_address')->nullable();
            $table->enum('pipeline_stage', ['lead', 'prospect', 'active', 'churned'])->default('lead');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // FK to referrers added later (referrers table not yet created)
            $table->unsignedBigInteger('referred_by')->nullable()->index();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('pipeline_stage');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
