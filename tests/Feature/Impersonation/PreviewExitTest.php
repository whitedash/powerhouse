<?php

namespace Tests\Feature\Impersonation;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression suite for preview/impersonation EXIT.
 *
 * The bug: referrers share the staff 'web' guard, so entering a referrer
 * preview replaced the operator's session entirely. The old "Exit
 * preview" path just logged out — stranding the admin on the login
 * screen. Exit must RESTORE the stored super_admin instead.
 *
 * The migration set uses MySQL-only DDL (multi-table UPDATE..JOIN,
 * ALTER..MODIFY..ENUM), so RefreshDatabase can't build the schema on the
 * sqlite :memory: default. Skip there; run under a MySQL test connection
 * (DB_CONNECTION=mysql DB_DATABASE=… php artisan test) / CI.
 */
class PreviewExitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Check the effective driver from the process env BEFORE booting
        // — RefreshDatabase migrates inside parent::setUp(), so a later
        // check would already have hit the MySQL-only DDL on sqlite.
        if ((getenv('DB_CONNECTION') ?: 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Preview-exit tests require a MySQL test database (migrations use MySQL-only DDL).');
        }

        parent::setUp();
    }

    public function test_referrer_exit_restores_the_admin_session(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $referrer = User::factory()->referrer()->create();

        // Simulate being mid-preview: signed in as the referrer on the
        // web guard, with the originating admin id stashed in session.
        $response = $this->actingAs($referrer)
            ->withSession([
                'referrer_preview_mode' => true,
                'referrer_preview_admin_id' => $admin->id,
                'referrer_preview_return_url' => route('internal.referrers.index'),
            ])
            ->get('/referrer/preview/exit');

        $response->assertRedirect(route('internal.referrers.index'));
        $this->assertAuthenticatedAs($admin);
        $response->assertSessionMissing('referrer_preview_mode');
        $response->assertSessionMissing('referrer_preview_admin_id');
    }

    public function test_referrer_exit_does_not_restore_a_non_admin_id(): void
    {
        $referrer = User::factory()->referrer()->create();
        $staff = User::factory()->create(); // role=staff, not super_admin

        $response = $this->actingAs($referrer)
            ->withSession([
                'referrer_preview_mode' => true,
                'referrer_preview_admin_id' => $staff->id,
            ])
            ->get('/referrer/preview/exit');

        // A poisoned/stale id that isn't a super_admin must not be
        // restored — fall through to a clean logout.
        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_referrer_exit_without_stored_admin_logs_out_cleanly(): void
    {
        $referrer = User::factory()->referrer()->create();

        $response = $this->actingAs($referrer)
            ->withSession(['referrer_preview_mode' => true])
            ->get('/referrer/preview/exit');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_portal_exit_returns_operator_to_internal_dashboard(): void
    {
        // Staff web session is independent of the portal guard. When the
        // operator is still signed in on web, exiting the portal preview
        // drops them back on the internal dashboard, not portal login.
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)
            ->withSession(['portal_preview_mode' => true])
            ->get('/portal/preview/exit');

        $response->assertRedirect(route('internal.dashboard'));
        $this->assertAuthenticatedAs($admin);
        $response->assertSessionMissing('portal_preview_mode');
    }
}
