<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    public function test_non_super_admin_cannot_view_or_run(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->get('/settings/deployment')->assertForbidden();
        $this->actingAs($staff)->post('/settings/deployment/migrate')->assertForbidden();
        $this->actingAs($staff)->post('/settings/deployment/clear-cache')->assertForbidden();
        $this->actingAs($staff)->post('/settings/deployment/run-both')->assertForbidden();
    }

    public function test_index_previews_migration_state_for_super_admin(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/settings/deployment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Internal/Settings/Deployment')
                ->has('migrations')
                ->has('pending')
                ->where('pending_count', fn ($v) => is_int($v))
                ->has('status_output')
            );
    }

    public function test_clear_cache_runs_and_is_logged(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post('/settings/deployment/clear-cache')
            ->assertRedirect()
            ->assertSessionHas('deployment_last_run');

        $this->assertDatabaseHas('activity_log', [
            'user_id' => $admin->id,
            'action' => 'deployment.clear-cache',
            'entity_type' => 'deployment',
        ]);
    }

    public function test_migrate_runs_and_is_logged(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post('/settings/deployment/migrate')
            ->assertRedirect();

        $this->assertDatabaseHas('activity_log', [
            'user_id' => $admin->id,
            'action' => 'deployment.migrate',
            'entity_type' => 'deployment',
        ]);
    }
}
