<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * @property int $id
 * @property int $customer_id
 * @property int|null $contact_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read Contact|null $contact
 */
class PortalUser extends Authenticatable implements OAuthenticatable
{
    // HasApiTokens enables $portalUser->createToken(...) for the
    // server-side SSO launch flow (Portal\ProductLaunchController).
    // The Passport guard is configured to authenticate portal_users,
    // so a personal access token minted here is verified by the
    // same provider on the way back in via /oauth/userinfo.
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'customer_id',
        'contact_id',
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Optional link back to the specific Contact this portal account
     * represents. Customer-wide invites (legacy / pre-contacts CRUD)
     * leave contact_id null; new invites always set it.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
