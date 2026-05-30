<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pivot model for the project ↔ user many-to-many. Mostly used when
 * code wants to query the pivot directly (e.g. "remove member X
 * from project Y"); routine reads still go via the BelongsToMany
 * relation on Project, which is cheaper.
 *
 * @property int $project_id
 * @property int $user_id
 * @property string $role
 * @property Carbon $joined_at
 * @property-read Project $project
 * @property-read User $user
 */
class ProjectMember extends Model
{
    protected $table = 'project_members';

    /** Composite primary key — no auto-incrementing id. */
    public $incrementing = false;

    /** Pivot has no created_at/updated_at — only joined_at. */
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'user_id',
        'role',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
