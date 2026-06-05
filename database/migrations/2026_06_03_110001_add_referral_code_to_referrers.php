<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each referrer gets ONE universal 8-char Crockford base32 code (the
 * canonical /r/{code} link). Nullable+unique so the column adds before
 * the backfill; the backfill below mints codes for existing referrers.
 *
 * The Crockford alphabet (no I/L/O/U) is inlined here rather than calling
 * App\Services\ReferralCodeGenerator — migrations must stay self-contained
 * so a future change to the service can't break a historical replay.
 * Runtime minting (ReferrerController::store) uses the service.
 */
return new class() extends Migration
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function up(): void
    {
        Schema::table('referrers', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique()->after('user_id');
        });

        // Backfill existing referrers with unique codes.
        $used = [];
        foreach (DB::table('referrers')->whereNull('referral_code')->pluck('id') as $id) {
            do {
                $code = $this->randomCode();
            } while (in_array($code, $used, true)
                || DB::table('referrers')->where('referral_code', $code)->exists());

            $used[] = $code;
            DB::table('referrers')->where('id', $id)->update(['referral_code' => $code]);
        }
    }

    private function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    public function down(): void
    {
        Schema::table('referrers', function (Blueprint $table) {
            $table->dropColumn('referral_code');
        });
    }
};
