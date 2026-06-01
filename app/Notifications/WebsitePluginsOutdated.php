<?php

namespace App\Notifications;

use App\Models\Website;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plugin-update alert for a managed website, raised by the
 * websites:sync-wordpress sweep when MainWP reports outdated plugins.
 *
 * Channels: database (bell) + mail when the notifiable has an address,
 * matching WebsiteDiskWarning and the other website notifications.
 */
class WebsitePluginsOutdated extends Notification
{
    public function __construct(
        public Website $website,
        public int $count,
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
            'type' => 'website_plugins_outdated',
            'title' => 'Plugin updates available',
            'message' => $this->count.' plugin(s) need updating on '.$this->website->url,
            'url' => '/customers/'.$this->website->customer_id,
            'icon' => 'ti-puzzle',
            'colour' => '#F59E0B',
            'entity_type' => 'website',
            'entity_id' => $this->website->id,
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
