<?php

use App\Support\DomainCustomerProductConverter;
use Illuminate\Database\Migrations\Migration;

/**
 * Stage 1a: remove the mislabeled domain-as-subscription. Converts every
 * is_domain CustomerProduct into a self-billing Domain (ensuring the Domain
 * exists first) and deletes the CP. See DomainCustomerProductConverter.
 *
 * On prod this is the single id=1 row ("WhiteDash .co.uk"); whitedash.co.uk
 * already exists, so it back-fills tld/plan/auto_renew (if needed) and deletes
 * the CP. Data-driven + idempotent: a DB with no domain CPs is a no-op.
 */
return new class() extends Migration
{
    public function up(): void
    {
        DomainCustomerProductConverter::run();
    }

    public function down(): void
    {
        // Irreversible: deleted CustomerProduct rows are not recreated. The
        // converted Domains remain (they are the canonical asset).
    }
};
