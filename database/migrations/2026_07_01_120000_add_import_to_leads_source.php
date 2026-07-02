<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'import' to the leads.source ENUM so CSV-imported leads are a
 * first-class, index-backed class of rows (leads has a (source, created_at)
 * index) instead of hiding under 'other'. The column is a native MySQL
 * ENUM, so this is a raw MODIFY COLUMN — mirrors the send_email /
 * partially_paid enum migrations. Conversion coercion: LeadController::
 * convert maps 'import' → acquisition_channel 'other' (customers' own ENUM
 * has no 'import' member).
 */
return new class() extends Migration
{
    private const WITH = "'manual','landing_page','facebook','google','referral','email','phone','event','word_of_mouth','other','import'";

    private const WITHOUT = "'manual','landing_page','facebook','google','referral','email','phone','event','word_of_mouth','other'";

    public function up(): void
    {
        DB::statement('ALTER TABLE leads MODIFY COLUMN source ENUM('.self::WITH.") NOT NULL DEFAULT 'manual'");
    }

    public function down(): void
    {
        // Narrowing an ENUM under strict mode fails while any row still
        // holds the removed value — remap imported rows onto 'other' (the
        // same bucket convert() coerces to) before the MODIFY.
        DB::statement("UPDATE leads SET source = 'other' WHERE source = 'import'");
        DB::statement('ALTER TABLE leads MODIFY COLUMN source ENUM('.self::WITHOUT.") NOT NULL DEFAULT 'manual'");
    }
};
