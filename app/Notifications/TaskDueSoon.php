<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A task assigned to the notifiable is due within the next 24 hours.
 * Dispatched by the notifications:check-overdue scheduled command,
 * guarded so a given task only fires this once per day.
 */
class TaskDueSoon extends Notification
{
    public function __construct(
        public Task $task,
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
            'type' => 'task_due_soon',
            'title' => 'Task due soon',
            'message' => 'Task due tomorrow: '.$this->task->title,
            'url' => '/activities/'.$this->task->id,
            'icon' => 'ti-clock',
            'colour' => '#F59E0B',
            'entity_type' => 'task',
            'entity_id' => $this->task->id,
        ];
    }

    // TODO(Postmark): build the MailMessage and add 'mail' to via().
    public function toMail(object $notifiable): ?MailMessage
    {
        return null;
    }
}
