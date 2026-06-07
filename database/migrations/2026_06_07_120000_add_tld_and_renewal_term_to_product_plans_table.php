<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TLD-driven domain pricing (refines Slice 1).
 *
 *  - tld : the top-level domain a domain plan prices (e.g. ".com", ".gr").
 *    Required for is_domain plans (enforced in the controller). The match
 *    point: a domain's TLD → its active is_domain plan. One active domain
 *    plan per TLD is enforced in the controller so the match is unambiguous.
 *
 * NB: an earlier draft of this migration also added renewal_interval_count /
 * renewal_interval_unit columns to pick which price tier the renewal billed.
 * That was simplified away before shipping — a domain plan now carries exactly
 * ONE active price tier, whose duration IS the renewal term and whose price IS
 * the renewal price (no separate renewal-term field). This migration was never
 * deployed, so it was amended in place to add only `tld` rather than leaving
 * an add-then-drop pair in history.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('product_plans', function (Blueprint $table): void {
            $table->string('tld', 20)->nullable()->after('is_domain');
            $table->index('tld');
        });
    }

    public function down(): void
    {
        Schema::table('product_plans', function (Blueprint $table): void {
            $table->dropIndex(['tld']);
            $table->dropColumn('tld');
        });
    }
};
