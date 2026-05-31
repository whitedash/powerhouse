<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound-email threading support. message_id stores the provider's
 * Message-ID so a customer reply (matched via In-Reply-To) routes back to
 * the right ticket; source records where the message originated.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->string('message_id', 255)->nullable()->after('ai_model');
            $table->string('source', 50)->nullable()->default('web')->after('message_id');

            $table->index('message_id', 'support_messages_message_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropIndex('support_messages_message_id_idx');
            $table->dropColumn(['message_id', 'source']);
        });
    }
};
