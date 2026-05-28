<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'trading_name',
        'company_number',
        'vat_number',
        'type',
        'address_line1',
        'address_line2',
        'city',
        'postcode',
        'country',
        'billing_address',
        'pipeline_stage',
        'assigned_to',
        'referred_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'billing_address' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(Referrer::class, 'referred_by');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class)->where('is_primary', true);
    }

    public function portalUsers(): HasMany
    {
        return $this->hasMany(PortalUser::class);
    }

    public function customerProducts(): HasMany
    {
        return $this->hasMany(CustomerProduct::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function referral(): HasOne
    {
        return $this->hasOne(CustomerReferral::class);
    }

    public function groups(): BelongsToMany
    {
        // Pivot table has only created_at (no updated_at) per SCHEMA.md,
        // so withTimestamps() is intentionally NOT chained here.
        return $this->belongsToMany(AccountGroup::class, 'customer_group_memberships', 'customer_id', 'group_id')
            ->withPivot('role', 'created_at');
    }

    /**
     * Constrain a query to the single customer a portal user belongs to.
     * Use everywhere portal-side: `Customer::forPortalUser($cid)->firstOrFail()`.
     */
    public function scopeForPortalUser(Builder $query, int $customerId): Builder
    {
        return $query->where('id', $customerId);
    }
}
