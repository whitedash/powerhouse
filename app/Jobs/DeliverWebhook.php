<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Background delivery of a single webhook. The retry policy (attempts,
 * exponential backoff, abandonment) lives in WebhookDispatcher::deliver,
 * so the job itself is tries=1 — a failed HTTP call updates the delivery
 * row and is re-picked-up by webhooks:retry-failed, not by the queue's
 * own retry machinery (which would double-count attempts).
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public WebhookDelivery $delivery)
    {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->delivery);
    }
}
