<?php

namespace App\Notifications;

use App\Models\Proposal;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A customer accepted a proposal. Sent to the proposal's author
 * (created_by) — the acceptance flow itself is public/unauthenticated.
 */
class ProposalAccepted extends Notification
{
    public function __construct(
        public Proposal $proposal,
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
        $this->proposal->loadMissing('customer:id,name');

        return [
            'type' => 'proposal_accepted',
            'title' => 'Proposal accepted',
            'message' => $this->proposal->customer->name.' accepted proposal '.$this->proposal->reference,
            'url' => '/proposals/'.$this->proposal->id,
            'icon' => 'ti-check',
            'colour' => '#10B981',
            'entity_type' => 'proposal',
            'entity_id' => $this->proposal->id,
        ];
    }

    // TODO(Postmark): build the MailMessage and add 'mail' to via().
    public function toMail(object $notifiable): ?MailMessage
    {
        return null;
    }
}
