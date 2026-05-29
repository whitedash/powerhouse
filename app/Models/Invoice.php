<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int $customer_id
 * @property int $billing_entity_id
 * @property string $type
 * @property string $status
 * @property string $subtotal
 * @property string $vat_rate
 * @property string $vat_amount
 * @property string $total
 * @property string $amount_paid
 * @property Carbon|null $issue_date
 * @property Carbon|null $due_date
 * @property Carbon|null $paid_at
 * @property string|null $payment_method
 * @property string|null $payment_reference
 * @property string|null $notes
 * @property string|null $pdf_path
 * @property Carbon|null $sent_at
 * @property int $reminder_count
 * @property Carbon|null $last_reminder_sent_at
 * @property Carbon|null $next_reminder_at
 * @property bool $reminders_paused
 * @property string|null $qbo_invoice_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read BillingEntity|null $billingEntity
 * @property-read User|null $createdBy
 * @property-read Collection<int, InvoiceLine> $lines
 */
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
        'reminder_count',
        'last_reminder_sent_at',
        'next_reminder_at',
        'reminders_paused',
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
            'last_reminder_sent_at' => 'datetime',
            'next_reminder_at' => 'datetime',
            'reminders_paused' => 'boolean',
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
