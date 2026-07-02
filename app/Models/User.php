<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role
 * @property string|null $avatar_colour
 * @property array<string, bool>|null $notification_preferences
 * @property string|null $two_factor_secret
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 * @property string|null $google_access_token
 * @property string|null $google_refresh_token
 * @property Carbon|null $google_token_expires_at
 * @property string|null $google_calendar_id
 * @property bool $google_sync_enabled
 * @property string|null $remember_token
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Referrer|null $referrer
 * @property-read Collection<int, Company> $assignedCustomers
 * @property-read Collection<int, SupportTicket> $assignedTickets
 * @property-read Collection<int, Task> $tasks
 */
class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;

    // Pin Spatie laravel-permission to the staff (web) guard. Additive +
    // INERT in phase 1: nothing reads roles/permissions for authorization
    // yet — enforcement still runs off the `role` enum (isStaff/isSuperAdmin).
    protected $guard_name = 'web';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar_colour',
        'notification_preferences',
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
        'google_calendar_id',
        'google_sync_enabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'google_access_token',
        'google_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'notification_preferences' => 'array',
            // OAuth tokens encrypted at rest — never plaintext in the DB.
            'google_access_token' => 'encrypted',
            'google_refresh_token' => 'encrypted',
            'google_token_expires_at' => 'datetime',
            'google_sync_enabled' => 'boolean',
        ];
    }

    /**
     * Whether this user wants in-app notifications of the given type.
     * A type the user has never toggled defaults to ON (true), so new
     * notification types light up for everyone until they opt out — the
     * one exception (invoice_overdue defaulting off) is seeded by the
     * account preferences form, not assumed here.
     */
    public function wantsNotification(string $type): bool
    {
        $prefs = $this->notification_preferences ?? [];

        return $prefs[$type] ?? true;
    }

    public function referrer(): HasOne
    {
        return $this->hasOne(Referrer::class);
    }

    public function assignedCustomers(): HasMany
    {
        return $this->hasMany(Company::class, 'assigned_to');
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'assigned_to');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['super_admin', 'staff'], true);
    }
}
