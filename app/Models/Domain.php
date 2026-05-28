<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $domain
 * @property string|null $cloudflare_zone_id
 * @property string|null $registrar
 * @property bool $is_in_cloudflare
 * @property bool $is_proxied
 * @property Carbon|null $expiry_date
 * @property Carbon|null $ssl_expiry_date
 * @property string|null $hosting_provider
 * @property Carbon|null $hosting_renewal_date
 * @property string|null $hosting_notes
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 */
class Domain extends Model
{
    protected $fillable = [
        'customer_id',
        'domain',
        'cloudflare_zone_id',
        'registrar',
        'is_in_cloudflare',
        'is_proxied',
        'expiry_date',
        'ssl_expiry_date',
        'hosting_provider',
        'hosting_renewal_date',
        'hosting_notes',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_in_cloudflare' => 'boolean',
            'is_proxied' => 'boolean',
            'expiry_date' => 'date',
            'ssl_expiry_date' => 'date',
            'hosting_renewal_date' => 'date',
            'last_synced_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
