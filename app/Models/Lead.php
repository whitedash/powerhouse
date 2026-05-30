<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $company
 * @property string|null $job_title
 * @property string $status
 * @property string $source
 * @property string|null $source_detail
 * @property int|null $assigned_to
 * @property string|null $estimated_value
 * @property string|null $notes
 * @property int|null $customer_id
 * @property Carbon|null $converted_at
 * @property string|null $lost_reason
 * @property int|null $form_submission_id
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $assignedTo
 * @property-read User $createdBy
 * @property-read Customer|null $customer
 * @property-read FormSubmission|null $formSubmission
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, Note> $notes_thread
 * @property-read string $name
 * @property-read string $initials
 * @property-read bool $is_converted
 * @property-read string $status_colour
 */
class Lead extends Model
{
    protected $table = 'leads';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'company',
        'job_title',
        'status',
        'source',
        'source_detail',
        'assigned_to',
        'estimated_value',
        'notes',
        'customer_id',
        'converted_at',
        'lost_reason',
        'form_submission_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
            'estimated_value' => 'decimal:2',
        ];
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The form_submission that produced this lead, if it was
     * captured automatically by WorkflowEngine::actionCreateLead().
     * Null for leads added by hand.
     */
    public function formSubmission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class);
    }

    /**
     * Tasks attached to this lead via tasks.lead_id. CRM tasks
     * may have customer_id OR lead_id (or neither for personal
     * todos); the column is mutually informative rather than
     * mutually exclusive.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'lead_id');
    }

    /**
     * Notes hung off the lead via notes.lead_id. Named
     * "notesThread" so it doesn't collide with the `notes`
     * TEXT column on this model (free-form notes the operator
     * typed into the lead detail form).
     */
    public function notesThread(): HasMany
    {
        return $this->hasMany(Note::class, 'lead_id');
    }

    /**
     * Display name — "First Last" or just "First" when surname
     * is missing. Used by the kanban card, the detail header,
     * and the customer lead_origin chip.
     */
    protected function name(): Attribute
    {
        return Attribute::get(
            fn (): string => trim($this->first_name.' '.($this->last_name ?? '')),
        );
    }

    /**
     * Avatar initials. Same shape as the staff initials helper
     * in Customer::primaryContact so cards stay visually
     * consistent across the app.
     */
    protected function initials(): Attribute
    {
        return Attribute::get(function (): string {
            $parts = array_filter([$this->first_name, $this->last_name]);
            $letters = array_map(fn (string $p): string => mb_substr($p, 0, 1), $parts);

            return mb_strtoupper(implode('', $letters));
        });
    }

    /**
     * customer_id is the single source of truth for "this lead
     * has converted". converted_at is informational; the FK
     * presence is what the kanban filters on.
     */
    protected function isConverted(): Attribute
    {
        return Attribute::get(fn (): bool => $this->customer_id !== null);
    }

    /**
     * Status colour for the kanban column header + the lead
     * card's left border. Hex literals are acceptable here
     * because the kanban explicitly maps stages to distinct
     * brand-adjacent palette swatches; the design-system
     * variables don't carry "pipeline stage" semantics.
     */
    protected function statusColour(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->status) {
            'new' => '#6366F1',
            'contacted' => '#3B82F6',
            'qualified' => '#F59E0B',
            'proposal' => '#8B5CF6',
            'negotiation' => '#EC4899',
            'won' => '#10B981',
            'lost' => '#EF4444',
            'unresponsive' => '#9CA3AF',
            default => '#6B7280',
        });
    }
}
