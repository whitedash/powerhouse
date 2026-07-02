<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved (vaulted) card (Billing P1). Holds only SAFE display metadata — the
 * card lives in Stripe, referenced by stripe_payment_method_id. There is no
 * column for a PAN/CVC/secret and there never should be.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $stripe_customer_id
 * @property string $stripe_payment_method_id
 * @property string|null $brand
 * @property string|null $last4
 * @property int|null $exp_month
 * @property int|null $exp_year
 * @property bool $is_default
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $label
 * @property-read Company|null $customer
 */
class PaymentMethod extends Model
{
    protected $fillable = [
        'customer_id',
        'stripe_customer_id',
        'stripe_payment_method_id',
        'brand',
        'last4',
        'exp_month',
        'exp_year',
        'is_default',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Company-safe one-liner, e.g. "Visa ending 4242 · exp 04/27".
     */
    protected function label(): Attribute
    {
        return Attribute::get(function (): string {
            $brand = ucfirst((string) ($this->brand ?? 'Card'));
            $base = $this->last4 ? "{$brand} ending {$this->last4}" : $brand;

            return $this->exp_month && $this->exp_year
                ? sprintf('%s · exp %02d/%02d', $base, $this->exp_month, $this->exp_year % 100)
                : $base;
        });
    }
}
