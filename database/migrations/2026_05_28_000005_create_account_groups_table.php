<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('account_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('customer_group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('account_groups')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'member'])->default('member');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['group_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_group_memberships');
        Schema::dropIfExists('account_groups');
    }
};
