<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_role',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
