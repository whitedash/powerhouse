<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One outbound webhook attempt-record. The WebhookDispatcher creates a
 * row in 'pending' before sending; deliver() updates status, attempts,
 * http_status and next_retry_at as the delivery succeeds, fails, or is
 * abandoned after max_attempts.
 *
 * @property int $id
 * @property string $event_type
 * @property string $product_slug
 * @property array<string, mixed> $payload
 * @property string $target_url
 * @property string $signature
 * @property string $status
 * @property int|null $http_status
 * @property string|null $response_body
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon|null $delivered_at
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookDelivery extends Model
{
    /**
     * In-memory defaults so a freshly create()d instance carries sane
     * values before a DB round-trip — otherwise attempts/max_attempts
     * are null in memory and canRetry() (used by the synchronous
     * delivery path) would short-circuit to false.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
        'max_attempts' => 3,
    ];

    protected $fillable = [
        'event_type',
        'product_slug',
        'payload',
        'target_url',
        'signature',
        'status',
        'http_status',
        'response_body',
        'attempts',
        'max_attempts',
        'delivered_at',
        'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'http_status' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'delivered_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    /**
     * Deliveries ready to be (re)sent now: still pending, and either
     * never scheduled or past their backoff window.
     *
     * @param  Builder<WebhookDelivery>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending')
            ->where(function (Builder $q): void {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            });
    }

    /**
     * Whether another delivery attempt is permitted — under the attempt
     * cap and not already in a terminal state.
     */
    public function canRetry(): bool
    {
        return $this->attempts < $this->max_attempts
            && $this->status !== 'delivered'
            && $this->status !== 'abandoned';
    }
}
