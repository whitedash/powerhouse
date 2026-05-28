<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
