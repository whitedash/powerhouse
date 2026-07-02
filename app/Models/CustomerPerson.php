<?php

namespace App\Models;

use App\Enums\PersonRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Explicit pivot for the customer↔person many-to-many. Extends Pivot (not
 * Model) so it slots straight into the belongsToMany ->using() on both
 * sides and exposes the typed `role` cast.
 *
 * @property int $customer_id
 * @property int $person_id
 * @property PersonRole $role
 * @property string|null $job_title
 */
class CustomerPerson extends Pivot
{
    protected $table = 'customer_person';

    public $incrementing = true;

    protected $fillable = [
        'customer_id',
        'person_id',
        'role',
        'job_title',
    ];

    protected function casts(): array
    {
        return [
            'role' => PersonRole::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
