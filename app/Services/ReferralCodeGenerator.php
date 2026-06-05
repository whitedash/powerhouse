<?php

namespace App\Services;

use App\Models\Referrer;

/**
 * Generates the 8-char Crockford base32 referral codes minted on each
 * referrer. Crockford's alphabet omits I, L, O and U to avoid visual
 * ambiguity — handy for codes a referrer might read aloud or type.
 *
 * The same alphabet/length is duplicated (deliberately) in the backfill
 * migration so migrations stay self-contained; this service is the
 * runtime source of truth for minting.
 */
class ReferralCodeGenerator
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 8;

    /**
     * A fresh code guaranteed not to collide with an existing referrer.
     * Loops on the (astronomically unlikely) collision; 32^8 ≈ 1.1e12
     * keyspace means this effectively never iterates.
     */
    public function generate(): string
    {
        do {
            $code = $this->random();
        } while (Referrer::where('referral_code', $code)->exists());

        return $code;
    }

    public function random(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Normalise user-supplied code input: upper-case and map Crockford's
     * forgiving aliases (I/L→1, O→0) so a mistyped link still resolves.
     */
    public static function normalise(string $code): string
    {
        $code = strtoupper(trim($code));

        return strtr($code, ['I' => '1', 'L' => '1', 'O' => '0']);
    }
}
