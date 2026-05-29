<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('reminder_count')->default(0)->after('sent_at');
            $table->timestamp('last_reminder_sent_at')->nullable()->after('reminder_count');
            $table->timestamp('next_reminder_at')->nullable()->after('last_reminder_sent_at');
            $table->boolean('reminders_paused')->default(false)->after('next_reminder_at');

            $table->index(['next_reminder_at', 'reminders_paused', 'status'], 'invoices_reminder_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_reminder_due_idx');
            $table->dropColumn([
                'reminder_count',
                'last_reminder_sent_at',
                'next_reminder_at',
                'reminders_paused',
            ]);
        });
    }
};
