<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated tri-state scope table for the roles & permissions system.
 *
 * Spatie laravel-permission handles the boolean permissions; the four
 * scoped sections (Projects/Tasks/Leads/Support) need an All/Assigned/None
 * scope per role, which is not a boolean — so it lives here, keyed by
 * (role, area). Timestamped AFTER create_permission_tables so the FK to
 * roles.id resolves. INERT in phase 1 (nothing reads it for enforcement).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('role_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            // Values mirror App\Enums\ScopeArea / App\Enums\AccessScope, which
            // are the code-side source of truth (the model casts to them).
            $table->enum('area', ['projects', 'tasks', 'leads', 'support']);
            $table->enum('scope', ['all', 'assigned', 'none']);
            $table->timestamps();

            // One scope per (role, area): invalid states unrepresentable.
            $table->unique(['role_id', 'area']);
            // Answers "which roles have area=X at scope=Y" (audit query).
            $table->index(['area', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_scopes');
    }
};
