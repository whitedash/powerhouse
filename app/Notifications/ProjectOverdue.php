<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A project's due_date has passed while it is still active. Sent to the
 * project lead by the notifications:check-overdue command, once per day.
 */
class ProjectOverdue extends Notification
{
    public function __construct(
        public Project $project,
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
            'type' => 'project_overdue',
            'title' => 'Project overdue',
            'message' => 'Project overdue: '.$this->project->title,
            'url' => '/projects/'.$this->project->id,
            'icon' => 'ti-alert-triangle',
            'colour' => '#EF4444',
            'entity_type' => 'project',
            'entity_id' => $this->project->id,
        ];
    }

    // TODO(Postmark): build the MailMessage and add 'mail' to via().
    public function toMail(object $notifiable): ?MailMessage
    {
        return null;
    }
}
