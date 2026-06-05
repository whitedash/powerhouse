<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Internal alert to the triage/support inbox that a new ticket was opened
 * (public guest form or workflow). Distinct from SupportTicketReceived
 * (the customer/guest acknowledgement): this goes to staff and deep-links
 * to the staff ticket view. Sent synchronously by TicketIntakeService,
 * inside the same try/catch as the ack — a notification failure must never
 * 500 or roll back the ticket.
 */
class SupportTicketStaffAlert extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(public SupportTicket $ticket) {}

    public function build(): self
    {
        $this->ticket->loadMissing(['messages' => fn ($q) => $q->orderBy('created_at')->limit(1)]);

        return $this
            ->subject('New support ticket #'.$this->ticket->id.' — '.$this->ticket->subject)
            ->view('emails.support-ticket-staff-alert')
            ->with([
                ...$this->getEntityData(null),
                'ticket' => $this->ticket,
                'firstMessage' => $this->ticket->messages->first()?->body,
                // @phpstan-ignore nullsafe.neverNull (support_tickets.customer_id is nullable for guest tickets; Larastan mistypes the belongsTo as non-null, so the ?-> is required at runtime)
                'fromName' => $this->ticket->guest_name ?? $this->ticket->customer?->name ?? 'Unknown',
                'fromEmail' => $this->ticket->guest_email ?? $this->ticket->customer?->primaryContact?->email,
                'ticketUrl' => route('internal.support.show', $this->ticket->id),
            ]);
    }
}
