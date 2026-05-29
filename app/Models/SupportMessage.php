<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ticket_id
 * @property string $sender_type
 * @property int|null $sender_id
 * @property string $body
 * @property bool $is_internal_note
 * @property string|null $ai_confidence
 * @property string|null $ai_model
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SupportTicket|null $ticket
 * @property-read User|null $sender
 */
class SupportMessage extends Model
{
    protected $fillable = [
        'ticket_id',
        'sender_type',
        'sender_id',
        'body',
        'is_internal_note',
        'ai_confidence',
        'ai_model',
    ];

    protected function casts(): array
    {
        return [
            'is_internal_note' => 'boolean',
            'ai_confidence' => 'decimal:2',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    /**
     * sender_id points at users only when sender_type='staff' (or
     * 'ai' which is system). For 'customer' messages there's no
     * matching users row; the relation resolves to null cleanly.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
