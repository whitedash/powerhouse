<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: SessionGuard::logout() fires the Logout event even when no
 * user is authenticated, so $event->user is null. LogSecurityEvent::onLogout
 * used to dereference it unconditionally and 500 with "Call to a member
 * function getAuthIdentifier() on null".
 *
 * The migration set uses MySQL-only DDL, so RefreshDatabase can't build the
 * schema on the sqlite :memory: default — skip there; runs under a MySQL
 * test connection (DB_CONNECTION=mysql DB_DATABASE=… php artisan test) / CI.
 */
class LogoutEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Requires a MySQL test database (migrations use MySQL-only DDL).');
        }

        parent::setUp();
    }

    public function test_logout_event_with_null_user_does_not_throw(): void
    {
        // The listener is wired in AppServiceProvider, so dispatching the
        // event exercises the real onLogout() handler.
        event(new Logout('web', null));

        $row = ActivityLog::where('action', 'auth.logout')->latest('id')->first();

        $this->assertNotNull($row, 'A userless logout should still be logged.');
        $this->assertNull($row->user_id);
        $this->assertNull($row->user_role);
        $this->assertSame('web', $row->after['guard'] ?? null);
    }
}
