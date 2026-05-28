<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'number',
        'customer_id',
        'billing_entity_id',
        'type',
        'status',
        'subtotal',
        'vat_rate',
        'vat_amount',
        'total',
        'amount_paid',
        'issue_date',
        'due_date',
        'paid_at',
        'payment_method',
        'payment_reference',
        'notes',
        'pdf_path',
        'sent_at',
        'qbo_invoice_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'issue_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }
}
