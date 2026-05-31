<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A pipeline lead was assigned to the notifiable user by someone else.
 */
class LeadAssigned extends Notification
{
    public function __construct(
        public Lead $lead,
        public User $assignedBy,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'lead_assigned',
            'title' => 'Lead assigned to you',
            'message' => $this->assignedBy->name.' assigned lead '.$this->lead->name.' to you',
            'url' => '/leads/'.$this->lead->id,
            'icon' => 'ti-user-plus',
            'colour' => '#8B5CF6',
            'entity_type' => 'lead',
            'entity_id' => $this->lead->id,
        ];
    }

    // TODO(Postmark): build the MailMessage and add 'mail' to via().
    public function toMail(object $notifiable): ?MailMessage
    {
        return null;
    }
}
