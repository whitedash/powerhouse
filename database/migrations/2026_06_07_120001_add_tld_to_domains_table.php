<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * domains.tld — the user-facing renewal control. A domain's TLD is matched
 * to an active is_domain product plan to resolve its renewal price + term.
 *
 * product_plan_id (from Slice 1) is kept as a DERIVED cached link (set on
 * save from the TLD match) for convenient relations/display; the TLD is the
 * authoritative match the renewal command re-resolves against.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->string('tld', 20)->nullable()->after('product_plan_id');
            $table->index('tld');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropIndex(['tld']);
            $table->dropColumn('tld');
        });
    }
};
