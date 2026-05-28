<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Referrer extends Model
{
    protected $fillable = [
        'user_id',
        'payment_details',
        'is_active',
    ];

    protected $hidden = ['payment_details'];

    protected function casts(): array
    {
        return [
            'payment_details' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(CustomerReferral::class);
    }

    public function customers(): HasManyThrough
    {
        return $this->hasManyThrough(Customer::class, CustomerReferral::class, 'referrer_id', 'id', 'id', 'customer_id');
    }

    public function commissionRules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedger::class);
    }
}
