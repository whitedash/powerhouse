<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $tier
 * @property string $subject
 * @property string $body
 * @property string $tone
 * @property bool $is_active
 * @property array<int, string>|null $variables_used
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReminderTemplate extends Model
{
    public const TIERS = ['due_soon', 'due_today', 'first_reminder', 'second_reminder', 'final_notice'];

    public const TONES = ['friendly', 'firm', 'urgent', 'final'];

    /**
     * Every {{placeholder}} the renderer recognises. Kept here so the
     * settings UI can advertise a variable reference and the seeder
     * can populate variables_used without typoing names.
     */
    public const AVAILABLE_VARIABLES = [
        'customer_name',
        'contact_name',
        'invoice_number',
        'invoice_amount',
        'due_date',
        'days_overdue',
        'days_until_due',
        'payment_ref',
        'bank_name',
        'account_number',
        'sort_code',
        'company_name',
        'portal_url',
    ];

    protected $fillable = [
        'name',
        'tier',
        'subject',
        'body',
        'tone',
        'is_active',
        'variables_used',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'variables_used' => 'array',
        ];
    }
}
