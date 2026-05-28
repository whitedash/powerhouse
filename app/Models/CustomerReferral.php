<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $referrer_id
 * @property Carbon|null $attributed_at
 * @property Carbon|null $created_at
 * @property-read Customer|null $customer
 * @property-read Referrer|null $referrer
 */
class CustomerReferral extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'referrer_id',
        'attributed_at',
    ];

    protected function casts(): array
    {
        return [
            'attributed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Referrer::class);
    }
}
