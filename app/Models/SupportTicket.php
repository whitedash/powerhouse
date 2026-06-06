<?php

namespace App\Models;

use App\Enums\SlaState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property int|null $contact_id
 * @property int|null $product_id
 * @property string $subject
 * @property string $status
 * @property string $priority
 * @property int|null $assigned_to
 * @property string|null $sentiment_score
 * @property Carbon|null $sla_breach_at
 * @property Carbon|null $first_responded_at
 * @property Carbon|null $reopened_at
 * @property int $reopen_count
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read Contact|null $contact
 * @property-read Product|null $product
 * @property-read User|null $assignedTo
 * @property-read Collection<int, SupportMessage> $messages
 * @property-read SupportMessage|null $latestPublicMessage
 */
class SupportTicket extends Model
{
    protected $fillable = [
        'customer_id',
        'guest_name',
        'guest_email',
        'guest_phone',
        'contact_id',
        'product_id',
        'subject',
        'status',
        'priority',
        'assigned_to',
        'sentiment_score',
        'sla_breach_at',
        'first_responded_at',
        'reopened_at',
        'reopen_count',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'sentiment_score' => 'decimal:2',
            'sla_breach_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'reopened_at' => 'datetime',
            'reopen_count' => 'integer',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * First-response SLA target (hours from creation) for a priority.
     * Single source of truth in config/support.php so the deadline math,
     * the badge and analytics can't drift.
     */
    public static function firstResponseHours(string $priority): int
    {
        $map = (array) config('support.first_response_hours', []);

        return (int) ($map[$priority] ?? $map['medium'] ?? 24);
    }

    /**
     * Derived first-response SLA state — computed on read, never stored.
     * Null when the ticket carries no deadline (legacy rows).
     */
    public function slaState(): ?SlaState
    {
        if ($this->sla_breach_at === null) {
            return null;
        }

        if ($this->first_responded_at !== null) {
            return $this->first_responded_at->lessThanOrEqualTo($this->sla_breach_at)
                ? SlaState::Met
                : SlaState::Breached;
        }

        // Resolved/closed without ever responding: settle the SLA at the
        // resolution time (never a live "due" countdown on a terminal ticket).
        if (in_array($this->status, ['resolved', 'closed'], true)) {
            $settledAt = $this->resolved_at ?? $this->closed_at;

            return $settledAt !== null && $settledAt->lessThanOrEqualTo($this->sla_breach_at)
                ? SlaState::Met
                : SlaState::Breached;
        }

        return now()->lessThan($this->sla_breach_at) ? SlaState::Due : SlaState::Breached;
    }

    /**
     * Seconds left until the first-response deadline — only while still Due
     * (unresponded, deadline in the future). Null otherwise.
     */
    public function slaRemainingSeconds(): ?int
    {
        if ($this->sla_breach_at === null || $this->first_responded_at !== null) {
            return null;
        }

        // Terminal tickets are settled, not counting down.
        if (in_array($this->status, ['resolved', 'closed'], true)) {
            return null;
        }

        $seconds = (int) round(now()->diffInSeconds($this->sla_breach_at, false));

        return $seconds > 0 ? $seconds : null;
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

    /**
     * The most recent customer-visible message — internal staff notes
     * (is_internal_note) are excluded so the portal ticket-list preview
     * never leaks staff-only commentary. Used for the last-message snippet
     * on the customer support page.
     */
    public function latestPublicMessage(): HasOne
    {
        return $this->hasOne(SupportMessage::class, 'ticket_id')
            ->where('is_internal_note', false)
            ->latestOfMany();
    }
}
