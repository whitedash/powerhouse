<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A staff reply pushed to the customer. The Reply-To is set to a
 * ticket-tagged address so a customer's reply threads back into this
 * ticket via the inbound-email webhook.
 */
class SupportTicketReply extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(
        public SupportTicket $ticket,
        public SupportMessage $message,
    ) {}

    public function build(): self
    {
        $inbound = (string) config('services.postmark.support_inbound_email', 'support@whitedash.com');
        // ticket+{id}@domain — InboundEmailController::extractTicketId matches
        // the literal "ticket+" prefix, so the local part is always "ticket"
        // (we only borrow the domain from the configured inbound address).
        $domain = explode('@', $inbound, 2)[1] ?? 'whitedash.com';
        $replyTo = 'ticket+'.$this->ticket->id.'@'.$domain;

        return $this
            ->replyTo($replyTo)
            ->subject('Re: #'.$this->ticket->id.' — '.$this->ticket->subject)
            ->view('emails.support-ticket-reply')
            ->with([
                ...$this->getEntityData(null),
                'ticket' => $this->ticket,
                'messageBody' => $this->message->body,
                'portalUrl' => rtrim((string) config('app.url'), '/').'/portal/support',
            ]);
    }
}
