<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
