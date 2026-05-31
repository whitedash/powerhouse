<?php

namespace App\Notifications;

use App\Models\Website;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Disk-space warning for a managed website, raised by the
 * websites:sync-hosting sweep when usage crosses 80% / 90%.
 *
 * Channels: database only for now (bell). Mail is stubbed until the
 * Postmark sprint, matching the other notification classes.
 */
class WebsiteDiskWarning extends Notification
{
    public function __construct(
        public Website $website,
        public string $level,
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
            'type' => 'website_disk_warning',
            'title' => $this->level === 'critical' ? 'Disk space critical' : 'Disk space warning',
            'message' => $this->website->url.' is '.$this->website->disk_percent.'% full',
            'url' => '/customers/'.$this->website->customer_id,
            'icon' => 'ti-database',
            'colour' => $this->level === 'critical' ? '#EF4444' : '#F59E0B',
            'entity_type' => 'website',
            'entity_id' => $this->website->id,
        ];
    }

    // TODO(Postmark): build the MailMessage and add 'mail' to via().
    public function toMail(object $notifiable): ?MailMessage
    {
        return null;
    }
}
