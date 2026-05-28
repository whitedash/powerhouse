<?php

namespace App\Services;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    public function __construct(private readonly Google2FA $engine = new Google2FA()) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function getQrCodeUrl(string $companyName, string $email, string $secret): string
    {
        return $this->engine->getQRCodeUrl($companyName, $email, $secret);
    }

    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->engine->verifyKey($secret, $code);
    }

    public function enable(User $user, string $secret): void
    {
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }
}
