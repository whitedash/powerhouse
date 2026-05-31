<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $category
 * @property string $description
 * @property string|null $supplier_name
 * @property int|null $supplier_id
 * @property string|null $qbo_bill_id
 * @property string $amount
 * @property string $vat_rate
 * @property string $vat_amount
 * @property string $total
 * @property Carbon $expense_date
 * @property string $status
 * @property bool $is_reimbursable
 * @property string|null $receipt_path
 * @property string|null $receipt_original_name
 * @property int|null $project_id
 * @property int|null $customer_id
 * @property int|null $commission_ledger_id
 * @property string|null $notes
 * @property int $created_by
 * @property int|null $approved_by
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project|null $project
 * @property-read Customer|null $customer
 * @property-read Supplier|null $supplier
 * @property-read CommissionLedger|null $commissionLedger
 * @property-read User $createdBy
 * @property-read User|null $approvedBy
 */
class Expense extends Model
{
    protected $fillable = [
        'category',
        'description',
        'supplier_name',
        'supplier_id',
        'qbo_bill_id',
        'amount',
        'vat_rate',
        'vat_amount',
        'total',
        'expense_date',
        'status',
        'is_reimbursable',
        'receipt_path',
        'receipt_original_name',
        'project_id',
        'customer_id',
        'commission_ledger_id',
        'notes',
        'created_by',
        'approved_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'paid_at' => 'datetime',
            'is_reimbursable' => 'boolean',
            'amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * Recompute `total` on every save so the column never drifts
     * from amount + vat_amount. Two callers that forget to set total
     * explicitly (the seeder and any future bulk import) get the
     * right number for free.
     */
    protected static function booted(): void
    {
        static::saving(function (Expense $e): void {
            // The decimal:2 cast types `total` as string for phpstan,
            // but Eloquent accepts a numeric value at assign time —
            // cast to string ourselves to satisfy the property type.
            $e->total = (string) round((float) $e->amount + (float) $e->vat_amount, 2);
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Display name for the payee: the linked supplier's name when an
     * FK is set, otherwise the legacy/ad-hoc free-text field.
     */
    public function displaySupplierName(): ?string
    {
        // Nullable belongsTo — larastan types the relation non-null, so
        // we branch with a truthy check rather than a nullsafe operator.
        return $this->supplier ? $this->supplier->name : $this->supplier_name;
    }

    public function commissionLedger(): BelongsTo
    {
        return $this->belongsTo(CommissionLedger::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
