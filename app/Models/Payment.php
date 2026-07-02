<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One settlement record against an invoice (Billing P1 ledger). Every settle —
 * on-session Stripe checkout and manual mark-paid — writes a row here, so the
 * ledger is complete before P2 introduces off-session charging.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $customer_id
 * @property string $amount
 * @property string $currency
 * @property string $rail
 * @property string|null $stripe_payment_intent_id
 * @property string $status
 * @property Carbon|null $attempted_at
 * @property string|null $failure_reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice|null $invoice
 * @property-read Company|null $customer
 */
class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        'customer_id',
        'amount',
        'currency',
        'rail',
        'stripe_payment_intent_id',
        'status',
        'attempted_at',
        'failure_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'attempted_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
