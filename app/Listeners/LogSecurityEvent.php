<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Request;

class LogSecurityEvent
{
    public function onLogin(Login $event): void
    {
        $this->log(
            action: 'auth.login',
            userId: $event->user->getAuthIdentifier(),
            userRole: $this->resolveRole($event->user),
            after: ['guard' => $event->guard],
        );
    }

    public function onFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        $this->log(
            action: 'auth.failed',
            entityType: 'user',
            userRole: null,
            after: ['email' => $email, 'guard' => $event->guard],
        );
    }

    public function onLogout(Logout $event): void
    {
        $this->log(
            action: 'auth.logout',
            userId: $event->user->getAuthIdentifier(),
            userRole: $this->resolveRole($event->user),
            after: ['guard' => $event->guard],
        );
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->log(
            action: 'auth.password_reset',
            userId: $event->user->getAuthIdentifier(),
            userRole: $this->resolveRole($event->user),
        );
    }

    private function resolveRole(Authenticatable $user): ?string
    {
        return $user instanceof User ? $user->role : null;
    }

    private function log(
        string $action,
        ?int $userId = null,
        string $entityType = 'user',
        ?string $userRole = null,
        array $after = [],
    ): void {
        ActivityLog::create([
            'user_id' => $userId,
            'user_role' => $userRole,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $userId,
            'after' => $after ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
        ]);
    }
}
