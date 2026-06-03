<?php

namespace App\Jobs;

use App\Models\CustomerProduct;
use App\Services\MyOrderPadProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Creates the MyOrderPad account for a freshly-activated subscription.
 *
 * Idempotent: re-checks the row at run time and no-ops if it's already
 * provisioned or no longer active, so a duplicate dispatch (or a retry
 * after MyOrderPad recovers) can't double-provision. ShouldBeUnique keeps
 * concurrent dispatches for the same subscription from racing.
 */
class ProvisionMyOrderPad implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry a few times — MyOrderPad may be briefly unreachable. */
    public int $tries = 3;

    public function __construct(public int $subscriptionId) {}

    /** 60s between attempts. */
    public function backoff(): int
    {
        return 60;
    }

    public function uniqueId(): string
    {
        return (string) $this->subscriptionId;
    }

    public function handle(MyOrderPadProvisioningService $provisioner): void
    {
        $sub = CustomerProduct::with('customer')->find($this->subscriptionId);

        if ($sub === null) {
            return;
        }

        // Already provisioned, or no longer active — nothing to do.
        if ($sub->external_user_id !== null) {
            return;
        }

        if (! in_array($sub->status, ['active', 'trial'], true)) {
            return;
        }

        $provisioner->provisionUser($sub);
    }
}
