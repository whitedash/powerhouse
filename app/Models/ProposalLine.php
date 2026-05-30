<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $proposal_id
 * @property string $description
 * @property string|null $note
 * @property string $quantity
 * @property string $unit_price
 * @property string $amount
 * @property string|null $discount_type
 * @property string $discount_value
 * @property string $discount_amount
 * @property int|null $product_id
 * @property int|null $plan_id
 * @property int $sort_order
 * @property-read Proposal $proposal
 * @property-read Product|null $product
 * @property-read ProductPlan|null $plan
 */
class ProposalLine extends Model
{
    protected $fillable = [
        'proposal_id',
        'description',
        'note',
        'quantity',
        'unit_price',
        'amount',
        'discount_type',
        'discount_value',
        'discount_amount',
        'product_id',
        'plan_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductPlan::class, 'plan_id');
    }
}
