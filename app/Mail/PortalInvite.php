<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\Contact;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Portal invitation carrying the one-time temporary password. The password
 * is passed in plaintext here only because it was just generated and never
 * persisted in the clear.
 */
class PortalInvite extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(
        public Customer $customer,
        public Contact $contact,
        public string $tempPassword,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("You've been invited to manage your account online")
            ->view('emails.portal-invite')
            ->with([
                ...$this->getEntityData(null),
                'contactName' => $this->contact->name,
                'loginEmail' => $this->contact->email,
                'tempPassword' => $this->tempPassword,
                'loginUrl' => rtrim((string) config('app.url'), '/').'/portal/login',
            ]);
    }
}
