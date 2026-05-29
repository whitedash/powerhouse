<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('maavelus_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_id')
                ->constrained('maavelus_statements')
                ->cascadeOnDelete();
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();
            $table->decimal('total_fees', 10, 2);
            $table->unsignedInteger('order_count')->nullable();
            $table->timestamps();

            $table->index('statement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maavelus_statement_lines');
    }
};
