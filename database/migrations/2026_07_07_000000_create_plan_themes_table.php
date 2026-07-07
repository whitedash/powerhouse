<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans-widget theming: plan_themes mirrors form_themes' shape exactly
 * (tokens is a PARTIAL override JSON; custom_css lives INSIDE tokens like
 * forms, not as a column). products.theme_id assigns a theme per product;
 * the single-plan embed inherits via plan → product → theme.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('plan_themes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->json('tokens');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('theme_id')->nullable()
                ->constrained('plan_themes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('theme_id');
        });
        Schema::dropIfExists('plan_themes');
    }
};
