<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('maavelus_statements', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_fees', 10, 2)->default(0);
            $table->unsignedInteger('total_orders')->nullable();
            $table->enum('status', ['draft', 'confirmed'])->default('draft');
            $table->text('notes')->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->enum('data_source', ['manual', 'api'])->default('manual');
            $table->boolean('commissions_generated')->default(false);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // One statement per month — duplicate creation refuses cleanly.
            $table->unique('period_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maavelus_statements');
    }
};
