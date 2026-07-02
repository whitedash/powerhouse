<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A cross-company human identity. Distinct from Contact (which is an
 * operational, per-company recipient row): a Person can be associated
 * with many companies via the `customer_person` pivot, with a role per
 * association. The optional Contact->person link ties an operational
 * contact back to the person it represents.
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Company> $companies
 * @property-read Collection<int, Contact> $contacts
 * @property-read User|null $createdBy
 * @property-read string $display_name
 */
class Person extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'notes',
        'created_by',
    ];

    /**
     * The companies this person owns / is associated with. Role and
     * job_title live on the pivot; CustomerPerson casts role to the
     * PersonRole enum.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'customer_person')
            ->withPivot('role', 'job_title')
            ->withTimestamps()
            ->using(CustomerPerson::class);
    }

    /**
     * Operational contact rows that have been linked to this person.
     * Optional — many people will have none.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Falls back through name → email so a partially-filled person still
     * renders something readable in lists.
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            if (! empty($this->name)) {
                return (string) $this->name;
            }

            return (string) ($this->email ?? 'Person');
        });
    }
}
