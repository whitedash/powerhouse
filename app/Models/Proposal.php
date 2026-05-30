<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int|null $billing_entity_id
 * @property int|null $project_id
 * @property int|null $contract_id
 * @property string $reference
 * @property string $title
 * @property string|null $description
 * @property string|null $terms
 * @property string $status
 * @property string $subtotal
 * @property string $discount_amount
 * @property string $vat_rate
 * @property string $vat_amount
 * @property string $total
 * @property Carbon|null $valid_until
 * @property Carbon|null $sent_at
 * @property string|null $acceptance_token
 * @property Carbon|null $acceptance_token_expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_by_name
 * @property string|null $accepted_ip
 * @property string|null $accepted_user_agent
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property string|null $pdf_path
 * @property string|null $accepted_pdf_path
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 * @property-read BillingEntity|null $billingEntity
 * @property-read Project|null $project
 * @property-read Contract|null $contract
 * @property-read Collection<int, ProposalLine> $lines
 * @property-read User $createdBy
 * @property-read PaymentSchedule|null $paymentSchedule
 * @property-read string $status_label
 * @property-read bool $is_expired
 */
class Proposal extends Model
{
    protected $fillable = [
        'customer_id',
        'billing_entity_id',
        'project_id',
        'contract_id',
        'reference',
        'title',
        'description',
        'terms',
        'status',
        'subtotal',
        'discount_amount',
        'vat_rate',
        'vat_amount',
        'total',
        'valid_until',
        'sent_at',
        'acceptance_token',
        'acceptance_token_expires_at',
        'accepted_at',
        'accepted_by_name',
        'accepted_ip',
        'accepted_user_agent',
        'rejected_at',
        'rejection_reason',
        'pdf_path',
        'accepted_pdf_path',
        'notes',
        'created_by',
    ];

    /** @var list<string> */
    protected $appends = ['status_label', 'is_expired'];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'acceptance_token_expires_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProposalLine::class)->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A proposal has at most one payment schedule. The schedule
     * carries its own customer_id mirror so it can outlive the
     * proposal (e.g. if a converted contract is later edited).
     */
    public function paymentSchedule(): HasOne
    {
        return $this->hasOne(PaymentSchedule::class);
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->status) {
            'draft' => 'Draft',
            'sent' => 'Sent — awaiting response',
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
            'expired' => 'Expired',
            default => ucfirst($this->status),
        });
    }

    /**
     * "Expired" is computed rather than persisted because the
     * customer's clock is the source of truth — a cron-flipped
     * column would lag and create awkward "we said it expires
     * tomorrow but accepted today" edge cases. Only sent
     * proposals expire; draft/accepted are out of scope.
     */
    protected function isExpired(): Attribute
    {
        return Attribute::get(
            fn (): bool => $this->valid_until instanceof Carbon
                && $this->valid_until->isPast()
                && $this->status === 'sent'
        );
    }

    /**
     * Year-scoped sequential reference. PROP-2026-0001 is
     * unique system-wide via the column constraint; this
     * helper just picks the next free integer for the year.
     * Run inside the creating transaction so two concurrent
     * stores don't both pick the same number.
     */
    public static function generateNextReference(): string
    {
        $year = now()->year;
        $latest = static::whereYear('created_at', $year)
            ->orderByDesc('id')
            ->value('reference');

        if (! $latest) {
            return 'PROP-'.$year.'-0001';
        }

        preg_match('/(\d+)$/', $latest, $m);
        $next = ((int) ($m[1] ?? 0)) + 1;

        return 'PROP-'.$year.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
