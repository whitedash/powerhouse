<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A task was assigned to the notifiable user by someone else.
 *
 * Channels: database only for now. The mail channel is stubbed
 * (toMail returns null) and will be switched on by adding 'mail' to
 * via() once the Postmark sprint lands. Preference gating happens in
 * NotificationService before this is ever dispatched.
 */
class TaskAssigned extends Notification
{
    public function __construct(
        public Task $task,
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
            'type' => 'task_assigned',
            'title' => 'Task assigned to you',
            'message' => $this->assignedBy->name.' assigned "'.$this->task->title.'" to you',
            'url' => '/activities/'.$this->task->id,
            'icon' => 'ti-checkbox',
            'colour' => '#3B82F6',
            'entity_type' => 'task',
            'entity_id' => $this->task->id,
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
