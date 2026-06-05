<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Acknowledgement to a GUEST who submitted a ticket via the public
 * /support form. Distinct from SupportTicketCreated (which is sent to a
 * known customer contact and links to the portal): a guest has no portal
 * account, so this template tells them we'll reply by email and to keep
 * the ticket reference.
 *
 * Sent synchronously by TicketIntakeService so the ack arrives regardless
 * of queue-worker health.
 */
class SupportTicketReceived extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(public SupportTicket $ticket) {}

    public function build(): self
    {
        return $this
            ->subject("We've received your request — ticket #".$this->ticket->id)
            ->view('emails.support-ticket-received')
            ->with([
                ...$this->getEntityData(null),
                'ticket' => $this->ticket,
            ]);
    }
}
