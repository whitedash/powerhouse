<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Plans-widget checkout initiation (PLANS-WIDGET-DESIGN.md §3b
 * addendum). Deliberately DECOUPLED from Company/Contact/Person/Invoice —
 * those are only ever written by the settlement webhook; this row exists
 * so an attempt that never settles is visible instead of vanishing.
 *
 * Lifecycle: pending (checkout-init) → completed (webhook settle, by
 * session id — a late completion overrides an abandoned mark, since
 * Stripe sessions stay payable up to their 24h expiry) or → abandoned
 * (plans:reconcile-abandoned-checkouts after the window).
 *
 * @property int $id
 * @property int|null $plan_price_id
 * @property string $purchaser_name
 * @property string $purchaser_email
 * @property string $stripe_checkout_session_id
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $abandoned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductPlanPrice|null $planPrice
 */
class PlanCheckoutAttempt extends Model
{
    protected $fillable = [
        'plan_price_id',
        'purchaser_name',
        'purchaser_email',
        'stripe_checkout_session_id',
        'status',
        'started_at',
        'completed_at',
        'abandoned_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'abandoned_at' => 'datetime',
        ];
    }

    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(ProductPlanPrice::class, 'plan_price_id');
    }
}
