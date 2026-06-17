<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Per-step draft of an in-progress multi-step form submission.
 *
 * A draft accumulates `data` across steps and is promoted to a
 * form_submissions row (status='new', which fires the WorkflowEngine as
 * today) on final submit — after which the draft row is deleted. This table
 * is the ONLY place draft/resume state lives; form_submissions is unchanged.
 *
 * Resume identity:
 *   - draft_token: anonymous resume, carried in the embed widget's
 *     localStorage. Always generated (see generateToken()).
 *   - portal_user_id: authenticated Portal resume (nullable = anonymous).
 *
 * @property int $id
 * @property int $form_id
 * @property string $draft_token
 * @property int|null $portal_user_id
 * @property int $current_step
 * @property array<string, mixed>|null $data
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $referrer_url
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Form $form
 * @property-read PortalUser|null $portalUser
 */
class FormSubmissionDraft extends Model
{
    protected $table = 'form_submission_drafts';

    protected $fillable = [
        'form_id',
        'draft_token',
        'portal_user_id',
        'current_step',
        'data',
        'ip_address',
        'user_agent',
        'referrer_url',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'current_step' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class);
    }

    /**
     * Build the unguessable resume token from the row id (mirrors the
     * proposals acceptance-token pattern). Generated AFTER the row is created
     * so the id is available, then saved back onto draft_token.
     */
    public static function generateToken(int $id): string
    {
        return hash('sha256', $id.Str::random(32).config('app.key'));
    }
}
