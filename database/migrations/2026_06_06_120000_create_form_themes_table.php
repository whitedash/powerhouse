<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_themes — reusable visual themes for embeddable forms.
 *
 * Standalone and NOT coupled to the websites module: a theme is just a
 * named bag of design tokens that any number of forms can point at via
 * forms.theme_id. `tokens` is a partial override set — the effective
 * values a form renders with come from App\Support\FormThemeTokens (the
 * default token set merged with these overrides), so a theme only needs
 * to carry the keys it actually changes.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('form_themes', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // Partial override set, e.g. {"accent":"#0ea5e9","radius":"12px"}.
            // Resolved against FormThemeTokens::defaults() at render time.
            $table->json('tokens');

            $table->foreignId('created_by')
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_themes');
    }
};
