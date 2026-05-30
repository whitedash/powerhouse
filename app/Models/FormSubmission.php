<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw record of a single form submission.
 *
 * Written before the WorkflowEngine runs so a thrown action
 * still leaves a forensic trace. status transitions:
 *   new -> processed   (engine ran cleanly)
 *   new -> error       (engine threw; row stays for debugging)
 *   new -> spam        (manual flag from FormSubmission viewer)
 *
 * lead_id is back-stamped if a workflow's create_lead action
 * fired — that's the bridge surfaced as "Submission -> Lead" in
 * the Form's submissions table view.
 *
 * @property int $id
 * @property int $form_id
 * @property array<string, mixed> $data
 * @property string $status
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $referrer_url
 * @property int|null $lead_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Form $form
 * @property-read Lead|null $lead
 */
class FormSubmission extends Model
{
    protected $table = 'form_submissions';

    protected $fillable = [
        'form_id',
        'data',
        'status',
        'ip_address',
        'user_agent',
        'referrer_url',
        'lead_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
