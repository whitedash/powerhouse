<?php

namespace App\Notifications;

use App\Models\ProjectFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts staff when an uploaded project file was flagged as malicious by
 * ClamAV and deleted. The file's project/uploader are captured eagerly so
 * the message renders even after the row is gone.
 */
class InfectedFileDetected extends Notification
{
    public function __construct(public ProjectFile $file) {}

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
        // uploaded_by + project_id are NOT NULL (RESTRICT / CASCADE) so both
        // relations always resolve; the job eager-loads them before notifying.
        $uploader = $this->file->uploadedBy->name;
        $project = $this->file->project->title;

        return [
            'type' => 'infected_file_detected',
            'title' => 'Malicious file detected and deleted',
            'message' => 'Malicious file detected and deleted: '.$this->file->filename
                .' uploaded by '.$uploader.' on project '.$project,
            'url' => '/projects/'.$this->file->project_id,
            'icon' => 'ti-virus',
            'colour' => '#EF4444',
            'entity_type' => 'project',
            'entity_id' => $this->file->project_id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage())
            ->error()
            ->subject($data['title'])
            ->line($data['message'])
            ->action('View project', rtrim((string) config('app.url'), '/').($data['url'] ?? '/'));
    }
}
