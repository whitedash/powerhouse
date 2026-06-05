<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable click event on a referral link. Written by
 * ReferralRedirectController and read by AttributionService for
 * last-touch resolution. No updated_at — clicks never change.
 *
 * @property int $id
 * @property int $referrer_id
 * @property string $referral_code
 * @property string|null $product
 * @property string|null $campaign
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $landing_url
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property-read Referrer|null $referrer
 */
class ReferralClick extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'referrer_id',
        'referral_code',
        'product',
        'campaign',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'landing_url',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Referrer::class);
    }
}
