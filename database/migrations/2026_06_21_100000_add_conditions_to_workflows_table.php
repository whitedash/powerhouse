<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * workflows.conditions — optional field-value gate evaluated by WorkflowEngine
 * after the trigger_config match and before the action transaction. NULL/empty
 * means no gating (the workflow fires as it did before). Shape:
 *   {logic, groups:[{logic, conditions:[{field_key, operator, value}]}]}.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->json('conditions')->nullable()->after('trigger_config');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('conditions');
        });
    }
};
