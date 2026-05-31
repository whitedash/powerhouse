<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\PortalUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Portal password reset. The codebase uses a secure, link-based reset
 * (the customer sets their own password) rather than emailing a plaintext
 * temporary password, so this carries the one-time reset URL.
 */
class PortalPasswordReset extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(
        public PortalUser $portalUser,
        public string $resetUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Reset your password')
            ->view('emails.portal-password-reset')
            ->with([
                ...$this->getEntityData(null),
                'name' => $this->portalUser->name,
                'resetUrl' => $this->resetUrl,
            ]);
    }
}
