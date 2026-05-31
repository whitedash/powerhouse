<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation to the customer that a support ticket was opened.
 */
class SupportTicketCreated extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(public SupportTicket $ticket) {}

    public function build(): self
    {
        return $this
            ->subject('Support ticket #'.$this->ticket->id.' opened — '.$this->ticket->subject)
            ->view('emails.support-ticket-created')
            ->with([
                ...$this->getEntityData(null),
                'ticket' => $this->ticket,
                'portalUrl' => rtrim((string) config('app.url'), '/').'/portal/support',
            ]);
    }
}
