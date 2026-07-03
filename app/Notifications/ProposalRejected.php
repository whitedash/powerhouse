<?php

namespace App\Notifications;

use App\Models\Proposal;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A customer declined a proposal. Sent to the proposal's author
 * (created_by) — the rejection flow itself is public/unauthenticated.
 * Mirror of ProposalAccepted.
 */
class ProposalRejected extends Notification
{
    public function __construct(
        public Proposal $proposal,
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
        $this->proposal->loadMissing('customer:id,name');

        return [
            'type' => 'proposal_rejected',
            'title' => 'Proposal declined',
            'message' => $this->proposal->customer->name.' declined proposal '.$this->proposal->reference,
            'url' => '/proposals/'.$this->proposal->id,
            'icon' => 'ti-x',
            'colour' => '#EF4444',
            'entity_type' => 'proposal',
            'entity_id' => $this->proposal->id,
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
