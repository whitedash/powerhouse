<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOnboardingProgress extends Model
{
    protected $table = 'customer_onboarding_progress';

    protected $fillable = [
        'customer_id',
        'sequence_id',
        'current_step',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(OnboardingSequence::class, 'sequence_id');
    }
}
