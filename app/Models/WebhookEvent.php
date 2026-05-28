<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'source',
        'event_id',
        'event_type',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
