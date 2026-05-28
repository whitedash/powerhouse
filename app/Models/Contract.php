<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    protected $fillable = [
        'customer_id',
        'created_by',
        'type',
        'title',
        'value',
        'status',
        'sent_at',
        'signed_at',
        'signed_ip',
        'countersigned_at',
        'start_date',
        'end_date',
        'pdf_path',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
            'countersigned_at' => 'datetime',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
