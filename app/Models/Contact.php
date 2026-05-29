<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $job_title
 * @property string $role
 * @property bool $is_primary
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read PortalUser|null $portalUser
 * @property-read string $display_name
 */
class Contact extends Model
{
    protected $fillable = [
        'customer_id',
        'name',
        'email',
        'phone',
        'job_title',
        'role',
        'is_primary',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Per-contact portal account, if one has been issued. The HasOne
     * leans on contact_id (added in
     * 2026_05_29_210001_add_contact_id_to_portal_users), so any
     * PortalUser row linked to this contact resolves here. Legacy
     * customer-wide portal users (contact_id IS NULL) intentionally
     * don't show up — they're surfaced separately on the Customer.
     */
    public function portalUser(): HasOne
    {
        return $this->hasOne(PortalUser::class, 'contact_id');
    }

    /**
     * Computed display label. Falls back through name → job_title →
     * email so a partially-filled contact still renders something
     * readable in lists.
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            if (! empty($this->name)) {
                return (string) $this->name;
            }
            if (! empty($this->job_title)) {
                return (string) $this->job_title;
            }

            return (string) ($this->email ?? 'Contact');
        });
    }
}
