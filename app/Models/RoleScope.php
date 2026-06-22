<?php

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * One tri-state section scope for a Spatie role: (role_id, area) -> scope.
 *
 * INERT in phase 1 — populated by RolesAndPermissionsSeeder but read by no
 * route, middleware, policy or controller for authorization yet. The
 * UNIQUE(role_id, area) DB constraint makes two scopes for the same
 * (role, area) impossible (invalid states unrepresentable).
 *
 * @property int $id
 * @property int $role_id
 * @property ScopeArea $area
 * @property AccessScope $scope
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Role $role
 */
class RoleScope extends Model
{
    protected $fillable = [
        'role_id',
        'area',
        'scope',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'area' => ScopeArea::class,
            'scope' => AccessScope::class,
        ];
    }
}
