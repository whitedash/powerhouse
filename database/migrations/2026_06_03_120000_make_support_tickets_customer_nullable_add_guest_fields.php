<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public submit-ticket support. A guest has no customer record, so
 * support_tickets.customer_id becomes nullable and three guest_* columns
 * carry the submitter's details. Staff/portal tickets are unaffected
 * (customer_id stays populated for them).
 *
 * MySQL won't ALTER a column that an FK depends on, so the customers FK is
 * dropped first, the column made nullable, then the FK re-added (still
 * cascadeOnDelete — guest rows simply have a null customer_id).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
        });

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();

            $table->string('guest_name')->nullable()->after('customer_id');
            $table->string('guest_email')->nullable()->after('guest_name');
            $table->string('guest_phone', 50)->nullable()->after('guest_email');

            $table->index('guest_email');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['guest_email']);
            $table->dropColumn(['guest_name', 'guest_email', 'guest_phone']);
        });

        // Re-tightening to NOT NULL will fail if any guest tickets (null
        // customer_id) exist — by design; delete them before rolling back.
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }
};
