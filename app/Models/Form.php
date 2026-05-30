<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Embeddable lead-capture form.
 *
 * Two public surfaces are exposed per row:
 *   - GET  /forms/{slug}/embed.js   (JavaScript widget)
 *   - POST /forms/{slug}/submit     (browser form post)
 *   - POST /webhooks/{slug}         (system-to-system, HMAC-signed)
 *
 * Use Form::active($slug) to fetch a publishable form — the
 * scope guarantees `status=active` so draft/inactive forms never
 * leak to public endpoints.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $slug
 * @property string $status
 * @property string $submit_button_text
 * @property string|null $success_message
 * @property string|null $redirect_url
 * @property bool $gdpr_consent_enabled
 * @property string|null $gdpr_consent_text
 * @property string $webhook_secret
 * @property int $submission_count
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $createdBy
 * @property-read Collection<int, FormField> $fields
 * @property-read Collection<int, FormSubmission> $submissions
 * @property-read string $embed_url
 * @property-read string $webhook_url
 */
class Form extends Model
{
    protected $table = 'forms';

    protected $fillable = [
        'name',
        'description',
        'slug',
        'status',
        'submit_button_text',
        'success_message',
        'redirect_url',
        'gdpr_consent_enabled',
        'gdpr_consent_text',
        'webhook_secret',
        'submission_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'gdpr_consent_enabled' => 'boolean',
            'submission_count' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Renderable fields in submit order. The form builder
     * persists sort_order so dragging in the slide-over
     * survives the round-trip.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort_order');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * Public URL of the embed snippet. Built off APP_URL so
     * staging deploys auto-rewrite without code changes.
     */
    protected function embedUrl(): Attribute
    {
        return Attribute::get(
            fn (): string => rtrim((string) config('app.url'), '/').'/forms/'.$this->slug.'/embed.js',
        );
    }

    /**
     * Public URL of the inbound webhook. The Forms/Index card
     * shows this so a Make/Zapier user can copy-paste it.
     */
    protected function webhookUrl(): Attribute
    {
        return Attribute::get(
            fn (): string => rtrim((string) config('app.url'), '/').'/webhooks/'.$this->slug,
        );
    }
}
