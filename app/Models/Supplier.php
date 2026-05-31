<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string|null $contact_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $address
 * @property string|null $account_number
 * @property string|null $payment_terms
 * @property string|null $default_expense_category
 * @property string $default_vat_rate
 * @property string|null $notes
 * @property bool $is_active
 * @property string|null $qbo_vendor_id
 * @property string $qbo_sync_status
 * @property Carbon|null $qbo_synced_at
 * @property string|null $qbo_sync_error
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Expense> $expenses
 * @property-read Collection<int, Product> $products
 * @property-read User $createdBy
 * @property-read float $monthly_cost
 * @property-read bool $is_qbo_synced
 * @property-read ProductSupplier $pivot
 */
class Supplier extends Model
{
    protected $table = 'suppliers';

    protected $fillable = [
        'name',
        'type',
        'contact_name',
        'email',
        'phone',
        'website',
        'address',
        'account_number',
        'payment_terms',
        'default_expense_category',
        'default_vat_rate',
        'notes',
        'is_active',
        'qbo_vendor_id',
        'qbo_sync_status',
        'qbo_synced_at',
        'qbo_sync_error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'default_vat_rate' => 'decimal:2',
            'qbo_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_suppliers')
            ->using(ProductSupplier::class)
            ->withPivot(['cost_per_unit', 'billing_interval', 'notes', 'sort_order'])
            ->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Total monthly cost across every linked product. Annual costs are
     * amortised to a per-month figure; quarterly likewise; one-off costs
     * are excluded (they're not a recurring monthly burden). Requires the
     * `products` relation — eager-load it before reading this accessor.
     */
    public function getMonthlyCostAttribute(): float
    {
        return (float) $this->products->sum(function (Product $p): float {
            $cost = (float) $p->pivot->cost_per_unit;

            return match ($p->pivot->billing_interval) {
                'monthly' => $cost,
                'quarterly' => $cost / 3,
                'annually' => $cost / 12,
                default => 0.0, // one_time — not a recurring monthly cost
            };
        });
    }

    public function getIsQboSyncedAttribute(): bool
    {
        return $this->qbo_sync_status === 'synced';
    }

    /**
     * Build an expense row from this supplier's defaults + monthly cost.
     * Called by the (future) recurring-expense command so supplier-linked
     * product costs can auto-generate the books entry. Kept here rather
     * than in a service because it's a pure factory off the model's own
     * state — no cross-aggregate orchestration.
     */
    public function createMonthlyExpense(): Expense
    {
        $amount = $this->monthly_cost;
        $vatRate = (float) $this->default_vat_rate;
        $vatAmount = round($amount * ($vatRate / 100), 2);

        return Expense::create([
            'supplier_id' => $this->id,
            'category' => $this->default_expense_category ?? 'other',
            'description' => $this->name.' — monthly',
            'amount' => $amount,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total' => round($amount + $vatAmount, 2),
            'expense_date' => now()->toDateString(),
            'status' => 'pending',
            'created_by' => $this->created_by,
        ]);
    }
}
