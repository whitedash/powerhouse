<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
