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
 * @property bool $is_recurring
 * @property int|null $recurring_interval_count
 * @property string|null $recurring_interval_unit
 * @property Carbon|null $recurring_next_date
 * @property Carbon|null $recurring_ends_at
 * @property int|null $parent_invoice_id
 * @property-read BillingEntity|null $billingEntity
 * @property-read User|null $createdBy
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Invoice|null $parentInvoice
 * @property-read Collection<int, Invoice> $childInvoices
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
        // Recurring template fields. is_recurring marks this invoice
        // as a template that auto-clones into draft children at the
        // set interval.
        'is_recurring',
        'recurring_interval_count',
        'recurring_interval_unit',
        'recurring_next_date',
        'recurring_ends_at',
        'parent_invoice_id',
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
            'is_recurring' => 'boolean',
            'recurring_interval_count' => 'integer',
            'recurring_next_date' => 'date',
            'recurring_ends_at' => 'date',
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

    /**
     * The recurring template this invoice was generated from. Children
     * created by invoices:generate-recurring carry this back-pointer
     * so the detail page can render a "generated from" breadcrumb.
     */
    public function parentInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_invoice_id');
    }

    /**
     * Children spawned from this recurring template. Lets the recurring
     * info card on the detail page list "5 child invoices generated".
     */
    public function childInvoices(): HasMany
    {
        return $this->hasMany(self::class, 'parent_invoice_id');
    }

    /**
     * Pessimistic-lock the latest INV-#### row and return the next
     * number in sequence. Must be called inside an open DB transaction
     * — the lock survives until COMMIT, blocking a parallel caller
     * from claiming the same number. Used by the InvoiceController
     * store() path, the invoices:generate-recurring artisan, and the
     * invoices:generate-subscriptions artisan so all three share one
     * source of truth for the numbering scheme.
     */
    public static function generateNextNumber(): string
    {
        $last = self::query()
            ->where('number', 'like', 'INV-%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('number');

        if ($last === null) {
            return 'INV-0001';
        }

        // Extract the trailing run of digits. The match() check is
        // defensive — a typo'd number would fall back to 1 rather
        // than crash the whole sweep.
        preg_match('/(\d+)$/', $last, $matches);
        $next = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;

        return 'INV-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
