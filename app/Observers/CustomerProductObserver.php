<?php

namespace App\Observers;

use App\Jobs\ProvisionMyOrderPad;
use App\Models\CustomerProduct;
use App\Models\Product;

/**
 * Auto-provisions the external account when a MyOrderPad subscription is
 * created or flips to active/trial. Centralising this on the model means
 * every path that activates a subscription (portal self-serve, staff
 * attach, provisioning flow, reinstate) triggers provisioning without
 * each controller having to remember to.
 */
class CustomerProductObserver
{
    public function created(CustomerProduct $customerProduct): void
    {
        $this->maybeProvision($customerProduct);
    }

    public function updated(CustomerProduct $customerProduct): void
    {
        // Only react to an actual status transition (e.g. → active on
        // reinstate). Without this guard the provisioning service's own
        // writes (provision_status / external_user_id) would re-enter here.
        if (! $customerProduct->wasChanged('status')) {
            return;
        }

        $this->maybeProvision($customerProduct);
    }

    private function maybeProvision(CustomerProduct $customerProduct): void
    {
        // Only when MyOrderPad is actually configured. Keeps local dev,
        // seeders, and the test suite from firing real provisioning HTTP
        // (the sync queue would otherwise run it inline).
        if (config('services.myorderpad.api_key') === null) {
            return;
        }

        // Already provisioned, or not in an active state — nothing to do.
        if ($customerProduct->external_user_id !== null) {
            return;
        }

        if (! in_array($customerProduct->status, ['active', 'trial'], true)) {
            return;
        }

        // Resolve the slug via a scalar query rather than the relation —
        // preventLazyLoading() is on outside production and an accessor
        // lazy-load from inside an observer would throw.
        $slug = Product::whereKey($customerProduct->product_id)->value('slug');

        if (! in_array($slug, ['myorderpad', 'orderpad'], true)) {
            return;
        }

        ProvisionMyOrderPad::dispatch($customerProduct->id);
    }
}
