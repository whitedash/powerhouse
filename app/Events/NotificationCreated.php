<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasting groundwork (NOT yet dispatched).
 *
 * Once Pusher credentials are added and BROADCAST_CONNECTION=pusher, the
 * notification pipeline can fire this to push new bell items to an open
 * tab in real time. It rides the standard per-user private channel
 * (App.Models.User.{id}) that Laravel Echo subscribes to out of the box.
 *
 * Until then nothing dispatches it, and with the default "log" driver a
 * dispatch would only write to the log — safe to wire up incrementally.
 */
class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $notification
     */
    public function __construct(
        public int $userId,
        public array $notification,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->userId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->notification;
    }
}
