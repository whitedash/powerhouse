<?php

namespace Tests\Feature;

use App\Enums\AccessScope;
use App\Enums\ScopeArea;
use App\Models\RoleScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_scope_per_role_area_is_enforced_by_the_unique_constraint(): void
    {
        $role = Role::findOrCreate('temp-scope-role', 'web');

        RoleScope::create([
            'role_id' => $role->id,
            'area' => ScopeArea::Projects->value,
            'scope' => AccessScope::All->value,
        ]);

        $this->expectException(QueryException::class);

        // Second row for the same (role, area) must be rejected.
        RoleScope::create([
            'role_id' => $role->id,
            'area' => ScopeArea::Projects->value,
            'scope' => AccessScope::None->value,
        ]);
    }

    public function test_area_and_scope_cast_to_enums(): void
    {
        $role = Role::findOrCreate('temp-cast-role', 'web');

        $row = RoleScope::create([
            'role_id' => $role->id,
            'area' => ScopeArea::Support->value,
            'scope' => AccessScope::Assigned->value,
        ])->fresh();

        $this->assertInstanceOf(ScopeArea::class, $row->area);
        $this->assertInstanceOf(AccessScope::class, $row->scope);
        $this->assertSame(ScopeArea::Support, $row->area);
        $this->assertSame(AccessScope::Assigned, $row->scope);
    }

    public function test_deleting_a_role_cascades_its_scopes(): void
    {
        $role = Role::findOrCreate('temp-cascade-role', 'web');
        RoleScope::create([
            'role_id' => $role->id,
            'area' => ScopeArea::Leads->value,
            'scope' => AccessScope::Assigned->value,
        ]);

        $role->delete();

        $this->assertSame(0, RoleScope::where('role_id', $role->id)->count());
    }
}
