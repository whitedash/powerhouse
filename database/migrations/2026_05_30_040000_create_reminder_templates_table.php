<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One reminder template per escalation tier. Tier is unique because
 * the rendering layer keys off it — when a reminder fires we look up
 * the template by tier and substitute {{variables}}.
 *
 * Body is LONGTEXT so the operator can write a multi-paragraph email
 * without bumping into a TEXT limit. variables_used documents which
 * placeholders the body references so the management UI can show a
 * usage hint; it's not enforced at write time.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            // One template per tier — the rendering layer looks the row
            // up by tier when a reminder fires. enum + unique together
            // make the constraint enforceable at the DB level.
            $table->enum('tier', [
                'due_soon',
                'due_today',
                'first_reminder',
                'second_reminder',
                'final_notice',
            ])->unique();
            $table->string('subject', 255);
            $table->longText('body');
            $table->enum('tone', ['friendly', 'firm', 'urgent', 'final']);
            $table->boolean('is_active')->default(true);
            $table->json('variables_used')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_templates');
    }
};
