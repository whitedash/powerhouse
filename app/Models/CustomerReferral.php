<?php

namespace App\Models;

use App\Enums\AttributionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One immutable attribution row per customer (unique on customer_id).
 * This IS the spec's "referrals" record — extended in place, never
 * superseded by a new table.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $referrer_id
 * @property int|null $lead_id
 * @property int|null $click_id
 * @property string|null $product
 * @property AttributionSource $source
 * @property string|null $campaign
 * @property Carbon|null $attributed_at
 * @property Carbon|null $converted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company|null $customer
 * @property-read Referrer|null $referrer
 * @property-read Lead|null $lead
 * @property-read ReferralClick|null $click
 */
class CustomerReferral extends Model
{
    // Rows are immutable once written; created_at is set via useCurrent at
    // the DB level and updated_at stays null.
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'referrer_id',
        'lead_id',
        'click_id',
        'product',
        'source',
        'campaign',
        'attributed_at',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => AttributionSource::class,
            'attributed_at' => 'datetime',
            'converted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Referrer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function click(): BelongsTo
    {
        return $this->belongsTo(ReferralClick::class, 'click_id');
    }
}
