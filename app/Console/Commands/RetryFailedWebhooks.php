<?php

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Console\Command;

/**
 * Re-attempt failed webhook deliveries whose backoff window has elapsed.
 * Abandoned deliveries (attempts exhausted) are deliberately excluded —
 * they only re-send via an explicit staff "Retry" from the delivery log.
 */
class RetryFailedWebhooks extends Command
{
    protected $signature = 'webhooks:retry-failed';

    protected $description = 'Re-deliver failed webhooks whose retry window has elapsed.';

    public function handle(WebhookDispatcher $dispatcher): int
    {
        // Failed (not abandoned) deliveries whose backoff window has
        // elapsed. We query 'failed' directly rather than via the
        // pending() scope — that scope filters to status='pending', so
        // combining it with status='failed' would match nothing.
        $due = WebhookDelivery::where('status', 'failed')
            ->where(function ($q): void {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->get();

        foreach ($due as $delivery) {
            $dispatcher->deliver($delivery);
        }

        $this->info("Processed {$due->count()} failed webhook".($due->count() === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
