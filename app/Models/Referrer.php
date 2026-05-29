<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property array<string, mixed>|null $payment_details
 * @property bool $is_active
 * @property int $customer_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Collection<int, CustomerReferral> $referrals
 * @property-read Collection<int, Customer> $customers
 * @property-read Collection<int, CommissionRule> $commissionRules
 * @property-read Collection<int, CommissionLedger> $ledgerEntries
 */
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
            // Bank details for commission payouts — encrypted at rest
            // (column widened to LONGTEXT in
            // 2026_05_29_200000_widen_referrer_payment_details_for_encryption
            // because encrypted payloads are not valid JSON).
            'payment_details' => 'encrypted:array',
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
