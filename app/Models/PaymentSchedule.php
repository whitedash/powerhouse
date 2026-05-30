<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int|null $proposal_id
 * @property int|null $project_id
 * @property int $customer_id
 * @property int|null $billing_entity_id
 * @property string $total
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proposal|null $proposal
 * @property-read Project|null $project
 * @property-read Customer $customer
 * @property-read BillingEntity|null $billingEntity
 * @property-read Collection<int, PaymentScheduleItem> $items
 * @property-read int $completion_percentage
 */
class PaymentSchedule extends Model
{
    protected $fillable = [
        'name',
        'proposal_id',
        'project_id',
        'customer_id',
        'billing_entity_id',
        'total',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentScheduleItem::class, 'schedule_id')
            ->orderBy('sort_order');
    }

    /**
     * Headline percentage for the schedule progress bar on the
     * proposal Show page. Counts paid items, not invoiced — the
     * operator wants to know how much real money has landed.
     */
    protected function completionPercentage(): Attribute
    {
        return Attribute::get(function (): int {
            $total = $this->items()->count();
            if ($total === 0) {
                return 0;
            }
            $paid = $this->items()->where('status', 'paid')->count();

            return (int) round(($paid / $total) * 100);
        });
    }
}
