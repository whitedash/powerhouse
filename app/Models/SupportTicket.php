<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int|null $contact_id
 * @property int|null $product_id
 * @property string $subject
 * @property string $status
 * @property string $priority
 * @property int|null $assigned_to
 * @property string|null $sentiment_score
 * @property Carbon|null $sla_breach_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read Contact|null $contact
 * @property-read Product|null $product
 * @property-read User|null $assignedTo
 * @property-read Collection<int, SupportMessage> $messages
 */
class SupportTicket extends Model
{
    protected $fillable = [
        'customer_id',
        'contact_id',
        'product_id',
        'subject',
        'status',
        'priority',
        'assigned_to',
        'sentiment_score',
        'sla_breach_at',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'sentiment_score' => 'decimal:2',
            'sla_breach_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }
}
