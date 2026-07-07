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
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_link
 * @property string|null $paid_via
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company|null $customer
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
        // Stripe online-payment fields. stripe_payment_link is the hosted
        // Checkout URL; paid_via records the channel (manual|stripe|bank).
        'stripe_payment_intent_id',
        'stripe_checkout_session_id',
        'stripe_payment_link',
        'paid_via',
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
        return $this->belongsTo(Company::class);
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
     * Settlement attempts against this invoice (Billing P1+ ledger). Dunning
     * (P3) derives its state from these rows — failed/requires_action attempts
     * give the attempt count + cadence anchor, with no extra schema.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
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
        // Derive the next number from the highest STRICTLY numeric-suffixed
        // invoice (INV- followed by digits, nothing else), NOT the latest
        // row by id. A manually-created or imported non-numeric number
        // (e.g. INV-DEMO-OVERDUE) must never become the anchor: under the
        // old orderByDesc('id') it fell through the digit parse to 1 and
        // returned INV-0001, colliding with the real first invoice and
        // aborting the whole transaction — and a webhook path (plan
        // settlement) would then retry into the same collision forever.
        //
        // REGEXP + SUBSTRING are MySQL-specific; the suite + prod both run
        // MySQL (see SCHEMA.md's plan-price UPDATE…JOIN note). lockForUpdate
        // on the selected row preserves the original serialisation: a
        // concurrent generator waits, then its current-read sees the newly
        // inserted higher number. The number column's UNIQUE index is the
        // final backstop for the (pre-existing, unchanged) empty-table
        // first-invoice race.
        $last = self::query()
            ->whereRaw("number REGEXP '^INV-[0-9]+$'")
            ->orderByRaw('CAST(SUBSTRING(number, 5) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('number');

        if ($last === null) {
            return 'INV-0001';
        }

        // The REGEXP guarantees a trailing digit run; the isset() stays as
        // defence in depth.
        preg_match('/(\d+)$/', $last, $matches);
        $next = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;

        return 'INV-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
