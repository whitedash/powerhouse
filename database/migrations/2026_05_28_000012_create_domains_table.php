<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain')->unique();
            $table->string('cloudflare_zone_id', 100)->nullable();
            $table->string('registrar', 100)->nullable();
            $table->boolean('is_in_cloudflare')->default(false);
            $table->boolean('is_proxied')->default(false);
            $table->date('expiry_date')->nullable();
            $table->date('ssl_expiry_date')->nullable();
            $table->string('hosting_provider', 100)->nullable();
            $table->date('hosting_renewal_date')->nullable();
            $table->text('hosting_notes')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('expiry_date');
            $table->index('ssl_expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
