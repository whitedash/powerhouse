<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $statement_id
 * @property int $customer_id
 * @property string $total_fees
 * @property int|null $order_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MaavelusStatement|null $statement
 * @property-read Customer|null $customer
 */
class MaavelusStatementLine extends Model
{
    protected $fillable = [
        'statement_id',
        'customer_id',
        'total_fees',
        'order_count',
    ];

    protected function casts(): array
    {
        return [
            'total_fees' => 'decimal:2',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(MaavelusStatement::class, 'statement_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
