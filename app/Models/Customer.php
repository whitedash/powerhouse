<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $trading_name
 * @property string|null $company_number
 * @property string|null $vat_number
 * @property string $type
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $postcode
 * @property string|null $country
 * @property array<string, mixed>|null $billing_address
 * @property string $pipeline_stage
 * @property int|null $assigned_to
 * @property int|null $referred_by
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $assignedTo
 * @property-read Referrer|null $referredBy
 * @property-read Collection<int, Contact> $contacts
 * @property-read Contact|null $primaryContact
 * @property-read Collection<int, PortalUser> $portalUsers
 * @property-read Collection<int, CustomerProduct> $customerProducts
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Collection<int, Domain> $domains
 * @property-read Collection<int, Contract> $contracts
 * @property-read Collection<int, SupportTicket> $supportTickets
 * @property-read Collection<int, Note> $notes
 * @property-read Collection<int, Task> $tasks
 * @property-read CustomerReferral|null $referral
 * @property-read Collection<int, AccountGroup> $groups
 */
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
        // How the customer found us — free-text channel + an optional
        // detail line (e.g. social_media + "LinkedIn"). Nullable.
        'acquisition_channel',
        'channel_detail',
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

    /**
     * Projects with this customer attached. Internal projects (no
     * customer) won't appear here — they're queried via Project
     * directly.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
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
