<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $referrer_id
 * @property int $customer_id
 * @property int|null $invoice_id
 * @property int $rule_id
 * @property int $product_id
 * @property string $trigger_type
 * @property string $status
 * @property string $gross_amount
 * @property string $commission_amount
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $paid_at
 * @property string|null $voided_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Referrer|null $referrer
 * @property-read Customer|null $customer
 * @property-read Invoice|null $invoice
 * @property-read CommissionRule|null $rule
 * @property-read Product|null $product
 * @property-read User|null $approvedBy
 */
class CommissionLedger extends Model
{
    protected $table = 'commission_ledger';

    protected $fillable = [
        'referrer_id',
        'customer_id',
        'invoice_id',
        'rule_id',
        'product_id',
        'trigger_type',
        'gross_amount',
        'commission_amount',
        'status',
        'period_start',
        'period_end',
        'approved_by',
        'approved_at',
        'paid_at',
        'voided_reason',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Referrer::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'rule_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
