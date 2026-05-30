<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int|null $created_by
 * @property string $type
 * @property string $title
 * @property string|null $description
 * @property string|null $value
 * @property string $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $signed_at
 * @property Carbon|null $countersigned_at
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string|null $pdf_path
 * @property string|null $file_original_name
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read User|null $createdBy
 * @property-read User|null $uploader
 * @property-read bool $is_expired
 * @property-read int|null $expires_in_days
 */
class Contract extends Model
{
    protected $fillable = [
        'customer_id',
        'created_by',
        'type',
        'title',
        'description',
        'value',
        'status',
        'sent_at',
        'signed_at',
        'signed_ip',
        'countersigned_at',
        'start_date',
        'end_date',
        'pdf_path',
        'file_original_name',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
            'countersigned_at' => 'datetime',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Alias for createdBy() — the Contracts tab speaks "uploader"
     * everywhere it shows the row, and `uploader` reads cleaner than
     * `createdBy` when paired with a download link in the UI.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * True when the contract should already have expired AND the
     * operator hasn't marked it as terminated yet. The "signed"
     * status is included alongside the schema's earlier flow states
     * (sent, countersigned) so any in-effect contract that's past
     * its end_date counts as expired.
     */
    protected function isExpired(): Attribute
    {
        return Attribute::get(fn (): bool => $this->end_date instanceof Carbon
            && $this->end_date->isPast()
            && in_array($this->status, ['signed', 'countersigned', 'sent'], true));
    }

    /**
     * Days until end_date. Negative when already past, null when no
     * end date is set. The Vue side uses the sign + magnitude to
     * pick the right warn-pill colour.
     */
    protected function expiresInDays(): Attribute
    {
        return Attribute::get(function (): ?int {
            if (! $this->end_date instanceof Carbon) {
                return null;
            }
            $today = now()->startOfDay();
            $end = $this->end_date->copy()->startOfDay();

            return (int) $today->diffInDays($end, false);
        });
    }
}
