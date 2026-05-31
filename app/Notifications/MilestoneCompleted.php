<?php

namespace App\Notifications;

use App\Models\Milestone;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every task under a milestone is complete. Sent to the project lead
 * and all project members (NotificationService fans it out).
 */
class MilestoneCompleted extends Notification
{
    public function __construct(
        public Milestone $milestone,
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
        $this->milestone->loadMissing('project:id,title');

        return [
            'type' => 'milestone_completed',
            'title' => 'Milestone completed',
            'message' => 'Milestone completed: '.$this->milestone->title
                .' on project '.$this->milestone->project->title,
            'url' => '/projects/'.$this->milestone->project_id,
            'icon' => 'ti-flag',
            'colour' => '#10B981',
            'entity_type' => 'milestone',
            'entity_id' => $this->milestone->id,
        ];
    }

    // TODO(Postmark): build the MailMessage and add 'mail' to via().
    public function toMail(object $notifiable): ?MailMessage
    {
        return null;
    }
}
