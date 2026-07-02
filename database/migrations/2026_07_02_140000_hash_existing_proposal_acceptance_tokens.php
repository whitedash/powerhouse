<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hash proposal acceptance tokens at rest. Previously the raw 256-bit token
 * (sha256 hex, 64 chars) was stored verbatim, so a DB read disclosed a live,
 * usable acceptance link. This re-hashes every stored token in place —
 * SHA2(token, 256) is again 64 hex chars, so it fits acceptance_token
 * VARCHAR(64) with no schema change.
 *
 * Safe against live data: the token that travels in the customer's emailed
 * link is the RAW value; the lookup (ProposalAcceptanceController::loadByToken)
 * now hashes the incoming raw token and matches it against this stored hash.
 * MySQL SHA2(x,256) and PHP hash('sha256', x) produce identical output, so a
 * proposal a customer already has open mid-flow still resolves after this runs.
 *
 * Irreversible: a hash cannot be turned back into the raw token, so down()
 * can only leave the (now-hashed) values in place. Rolling back does NOT
 * restore working raw-token storage; any outstanding link would then be a
 * double-hash mismatch until the proposal is re-sent / its link regenerated.
 */
return new class() extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE proposals SET acceptance_token = SHA2(acceptance_token, 256) WHERE acceptance_token IS NOT NULL');
    }

    public function down(): void
    {
        // No-op: hashing is one-way. See the class docblock — the raw tokens
        // are unrecoverable, so there is nothing to restore. Left explicit
        // rather than throwing so a full rollback of a batch doesn't abort.
    }
};
