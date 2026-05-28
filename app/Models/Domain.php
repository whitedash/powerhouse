<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
