<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string $total_fees
 * @property int|null $total_orders
 * @property string $status
 * @property string|null $notes
 * @property string|null $pdf_path
 * @property string $data_source
 * @property bool $commissions_generated
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MaavelusStatementLine> $lines
 * @property-read User|null $confirmedBy
 * @property-read User|null $createdBy
 */
class MaavelusStatement extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'total_fees',
        'total_orders',
        'status',
        'notes',
        'pdf_path',
        'data_source',
        'commissions_generated',
        'confirmed_by',
        'confirmed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_fees' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'commissions_generated' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaavelusStatementLine::class, 'statement_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function periodLabel(): string
    {
        return $this->period_start?->format('F Y') ?? '—';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
