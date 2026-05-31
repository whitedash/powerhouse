<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for the product_suppliers many-to-many. Typed so the
 * cost/interval/notes attributes are resolvable on `$model->pivot`
 * across both sides of the relation.
 *
 * @property int $product_id
 * @property int $supplier_id
 * @property string $cost_per_unit
 * @property string $billing_interval
 * @property string|null $notes
 * @property int $sort_order
 */
class ProductSupplier extends Pivot
{
    protected $table = 'product_suppliers';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'cost_per_unit' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
