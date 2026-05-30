<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $schedule_id
 * @property string $label
 * @property string|null $percentage
 * @property string $amount
 * @property string $trigger_type
 * @property Carbon|null $trigger_date
 * @property int|null $milestone_id
 * @property int|null $invoice_id
 * @property string $status
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PaymentSchedule $schedule
 * @property-read Milestone|null $milestone
 * @property-read Invoice|null $invoice
 * @property-read bool $is_triggerable
 */
class PaymentScheduleItem extends Model
{
    protected $fillable = [
        'schedule_id',
        'label',
        'percentage',
        'amount',
        'trigger_type',
        'trigger_date',
        'milestone_id',
        'invoice_id',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'amount' => 'decimal:2',
            'trigger_date' => 'date',
            'sort_order' => 'integer',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class, 'schedule_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * True when the operator can hit "Generate invoice now". Only
     * manual + pending items qualify; everything else needs the
     * milestone hook or the date cron (future sprint).
     */
    protected function isTriggerable(): Attribute
    {
        return Attribute::get(
            fn (): bool => $this->status === 'pending'
                && $this->trigger_type === 'manual'
        );
    }
}
