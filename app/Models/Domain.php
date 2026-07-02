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
 * @property Carbon|null $registered_at
 * @property bool $auto_renew
 * @property string $status
 * @property Carbon|null $expiry_date
 * @property Carbon|null $ssl_expiry_date
 * @property string $ssl_status
 * @property array<int, string>|null $nameservers
 * @property string|null $hosting_provider
 * @property Carbon|null $hosting_renewal_date
 * @property string|null $hosting_notes
 * @property string|null $notes
 * @property Carbon|null $last_synced_at
 * @property int|null $product_plan_id
 * @property string|null $tld
 * @property Carbon|null $renewal_invoiced_for
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company|null $customer
 * @property-read ProductPlan|null $plan
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
        'registered_at',
        'auto_renew',
        // TLD = the user-facing renewal control (matched to an is_domain
        // plan). product_plan_id is the derived cached link; the expiry
        // cycle the last renewal invoice covered is the idempotency marker.
        'tld',
        'product_plan_id',
        'renewal_invoiced_for',
        'status',
        'expiry_date',
        'ssl_expiry_date',
        'ssl_status',
        'nameservers',
        'hosting_provider',
        'hosting_renewal_date',
        'hosting_notes',
        'notes',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_in_cloudflare' => 'boolean',
            'is_proxied' => 'boolean',
            'auto_renew' => 'boolean',
            'registered_at' => 'date',
            'renewal_invoiced_for' => 'date',
            'expiry_date' => 'date',
            'ssl_expiry_date' => 'date',
            'hosting_renewal_date' => 'date',
            'last_synced_at' => 'datetime',
            'nameservers' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The product plan used as the renewal PRICE source (an is_domain
     * plan). Null = no automated renewal billing for this domain.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductPlan::class, 'product_plan_id');
    }
}
