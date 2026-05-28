<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $company_number
 * @property string|null $vat_number
 * @property array<string, mixed>|null $address
 * @property string|null $bank_name
 * @property string|null $sort_code
 * @property string|null $account_number
 * @property string|null $account_name
 * @property string|null $logo_path
 * @property string|null $postmark_sender_email
 * @property string|null $postmark_sender_name
 * @property string|null $postmark_domain
 * @property string|null $qbo_realm_id
 * @property string|null $qbo_access_token
 * @property string|null $qbo_refresh_token
 * @property Carbon|null $qbo_token_expires_at
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Invoice> $invoices
 * @property-read int|null $invoices_count
 * @property-read Collection<int, CustomerProduct> $customerProducts
 */
class BillingEntity extends Model
{
    protected $fillable = [
        'name',
        'legal_name',
        'company_number',
        'vat_number',
        'address',
        'bank_name',
        'sort_code',
        'account_number',
        'account_name',
        'logo_path',
        'postmark_sender_email',
        'postmark_sender_name',
        'postmark_domain',
        'qbo_realm_id',
        'qbo_access_token',
        'qbo_refresh_token',
        'qbo_token_expires_at',
        'is_active',
    ];

    protected $hidden = ['qbo_access_token', 'qbo_refresh_token'];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'qbo_token_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_code' => 'encrypted',
            'account_number' => 'encrypted',
            'account_name' => 'encrypted',
            'qbo_access_token' => 'encrypted',
            'qbo_refresh_token' => 'encrypted',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function customerProducts(): HasMany
    {
        return $this->hasMany(CustomerProduct::class);
    }
}
