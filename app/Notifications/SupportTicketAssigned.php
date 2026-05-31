<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A support ticket was assigned to the notifiable user by someone else.
 */
class SupportTicketAssigned extends Notification
{
    public function __construct(
        public SupportTicket $ticket,
        public User $assignedBy,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ! empty($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_assigned',
            'title' => 'Support ticket assigned',
            'message' => 'Support ticket assigned: '.$this->ticket->subject,
            'url' => '/support/'.$this->ticket->id,
            'icon' => 'ti-headset',
            'colour' => '#F59E0B',
            'entity_type' => 'support_ticket',
            'entity_id' => $this->ticket->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage())
            ->subject($data['title'])
            ->line($data['message'])
            ->action('View', rtrim((string) config('app.url'), '/').($data['url'] ?? '/'));
    }
}
