<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The flash → toast bridge only surfaces keys HandleInertiaRequests actually
 * shares. ToastContainer.vue listens for all four levels, so a key missing
 * here fails silently — the controller flashes, the session holds it, and
 * nothing ever renders (how warning/info went dark until 2026-07).
 */
class InertiaFlashShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_four_flash_levels_are_shared_to_inertia(): void
    {
        $staff = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($staff)
            ->withSession([
                'success' => 'saved',
                'error' => 'failed',
                'warning' => 'partial',
                'info' => 'note',
            ])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('flash.success', 'saved')
                ->where('flash.error', 'failed')
                ->where('flash.warning', 'partial')
                ->where('flash.info', 'note'));
    }
}
