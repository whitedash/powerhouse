<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Diagnostic email fired from Settings → Integrations to verify Postmark
 * delivery end-to-end.
 */
class TestEmail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(public string $senderName) {}

    public function build(): self
    {
        return $this
            ->subject('Postmark test — Powerhouse')
            ->view('emails.test')
            ->with([
                ...$this->getEntityData(null),
                'senderName' => $this->senderName,
                'sentAt' => now()->format('d M Y H:i'),
            ]);
    }
}
