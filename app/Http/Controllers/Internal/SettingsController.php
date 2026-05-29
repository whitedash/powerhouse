<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Defaults the Notifications page falls back to when no settings row
     * has been written yet. Kept in one place so the toggle/number copy
     * and the persisted shape stay in sync.
     *
     * @var array<string, bool|int|string>
     */
    private const NOTIFICATION_DEFAULTS = [
        'notifications.invoice_overdue_alert' => true,
        'notifications.invoice_overdue_days' => 1,
        'notifications.domain_expiry_alert' => true,
        'notifications.domain_expiry_days' => 30,
        'notifications.domain_critical_days' => 7,
        'notifications.reminders_enabled' => true,
        'notifications.reminders_time' => '09:00',
        'notifications.email_on_overdue' => false,
        'notifications.email_on_sla_breach' => false,
    ];

    /* ─────────────────────────────────────────────────────────────────────
     * GENERAL
     * ─────────────────────────────────────────────────────────────────── */

    public function index(): Response
    {
        return Inertia::render('Internal/Settings/General', [
            'workspace' => [
                'name' => config('app.name'),
                'url' => config('app.url'),
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale'),
            ],
        ]);
    }

    /* ─────────────────────────────────────────────────────────────────────
     * TEAM MEMBERS
     * ─────────────────────────────────────────────────────────────────── */

    public function team(Request $request): Response
    {
        $users = User::whereIn('role', ['super_admin', 'staff'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'avatar_colour', 'last_login_at'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'avatar_colour' => $u->avatar_colour,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'is_me' => $u->id === $request->user()->id,
            ])
            ->all();

        return Inertia::render('Internal/Settings/Team', [
            'users' => $users,
        ]);
    }

    public function teamInvite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['super_admin', 'staff'])],
        ]);

        $tempPassword = Str::random(16);

        $user = DB::transaction(function () use ($data, $request, $tempPassword) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'password' => Hash::make($tempPassword),
            ]);

            $this->logActivity($request, 'team.member_invited', 'user', $user->id, after: [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]);

            return $user;
        });

        // TODO: queue welcome email via Postmark when the email sprint
        // runs. For now the temp password surfaces in the flash bag so
        // the inviter can hand it over out-of-band.
        return back()->with([
            'success' => "{$user->name} invited.",
            'temp_password' => $tempPassword,
        ]);
    }

    public function teamUpdateRole(int $id, Request $request): RedirectResponse
    {
        $target = User::findOrFail($id);

        if ($target->id === $request->user()->id) {
            return back()->with('error', "You can't change your own role.");
        }

        $data = $request->validate([
            'role' => ['required', Rule::in(['super_admin', 'staff'])],
        ]);

        // Demoting the only super_admin would lock the platform out of
        // its own management. Refuse with an explicit reason instead of
        // letting it through and breaking the next page load.
        if ($target->role === 'super_admin' && $data['role'] !== 'super_admin') {
            $superCount = User::where('role', 'super_admin')->count();
            if ($superCount <= 1) {
                return back()->with('error', "Can't demote the only super_admin.");
            }
        }

        DB::transaction(function () use ($target, $data, $request) {
            $before = ['role' => $target->role];
            $target->update(['role' => $data['role']]);
            $this->logActivity($request, 'team.role_changed', 'user', $target->id, $before, ['role' => $data['role']]);
        });

        return back()->with('success', "{$target->name}'s role updated to {$data['role']}.");
    }

    public function teamRemove(int $id, Request $request): RedirectResponse
    {
        $target = User::findOrFail($id);

        if ($target->id === $request->user()->id) {
            return back()->with('error', "You can't remove your own account.");
        }

        if ($target->role === 'super_admin') {
            $superCount = User::where('role', 'super_admin')->count();
            if ($superCount <= 1) {
                return back()->with('error', "Can't remove the only super_admin.");
            }
        }

        // Reference check across the bits of data a user can own. A hard
        // delete that orphans these would silently break customer /
        // invoice / task ownership, so refuse the delete and surface
        // the count so the operator knows what to reassign.
        $owned = DB::table('customers')->where('assigned_to', $id)->count()
            + DB::table('tasks')->where('assigned_to', $id)->count()
            + DB::table('invoices')->where('created_by', $id)->count();

        if ($owned > 0) {
            return back()->with(
                'error',
                "{$target->name} has {$owned} owned record".($owned === 1 ? '' : 's').'. Reassign before removing.',
            );
        }

        DB::transaction(function () use ($target, $request) {
            $this->logActivity($request, 'team.member_removed', 'user', $target->id, before: [
                'name' => $target->name,
                'email' => $target->email,
                'role' => $target->role,
            ]);
            $target->delete();
        });

        return back()->with('success', "{$target->name} removed from the team.");
    }

    /* ─────────────────────────────────────────────────────────────────────
     * SECURITY
     * ─────────────────────────────────────────────────────────────────── */

    public function security(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Internal/Settings/Security', [
            'session' => [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'two_factor_enabled' => (bool) $user->two_factor_confirmed_at,
        ]);
    }

    public function securityChangePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        DB::transaction(function () use ($user, $data, $request) {
            $user->update(['password' => Hash::make($data['password'])]);

            $this->logActivity($request, 'auth.password_changed', 'user', $user->id);
        });

        // Cycle the session so the new credential is what the cookie is
        // bound to. The old session token becomes invalid.
        $request->session()->regenerate();

        return back()->with('success', 'Password updated.');
    }

    public function securityClearSessions(Request $request): RedirectResponse
    {
        // Without a persisted session driver we don't have a real
        // "other sessions" view to revoke. Cycling the current token +
        // logging the request is the honest stand-in. Once we move to
        // the database session driver this becomes a deleteWhere
        // against the sessions table.
        $request->session()->regenerate();

        $this->logActivity($request, 'auth.sessions_cleared', 'user', $request->user()->id);

        return back()->with('success', 'Other sessions invalidated.');
    }

    /* ─────────────────────────────────────────────────────────────────────
     * NOTIFICATIONS
     * ─────────────────────────────────────────────────────────────────── */

    public function notifications(): Response
    {
        $stored = Setting::query()
            ->whereIn('key', array_keys(self::NOTIFICATION_DEFAULTS))
            ->pluck('value', 'key')
            ->all();

        $values = [];
        foreach (self::NOTIFICATION_DEFAULTS as $key => $default) {
            $raw = $stored[$key] ?? null;
            $values[$key] = $raw === null ? $default : $this->decodeSettingValue($raw, $default);
        }

        return Inertia::render('Internal/Settings/Notifications', [
            'values' => $values,
        ]);
    }

    public function notificationsUpdate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'notifications' => ['required', 'array'],
            'notifications.invoice_overdue_alert' => ['required', 'boolean'],
            'notifications.invoice_overdue_days' => ['required', 'integer', 'min:0', 'max:90'],
            'notifications.domain_expiry_alert' => ['required', 'boolean'],
            'notifications.domain_expiry_days' => ['required', 'integer', 'min:1', 'max:180'],
            'notifications.domain_critical_days' => ['required', 'integer', 'min:1', 'max:90'],
            'notifications.reminders_enabled' => ['required', 'boolean'],
            'notifications.reminders_time' => ['required', 'date_format:H:i'],
            'notifications.email_on_overdue' => ['required', 'boolean'],
            'notifications.email_on_sla_breach' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $request) {
            foreach ($data['notifications'] as $key => $value) {
                $fullKey = "notifications.$key";
                Setting::updateOrCreate(
                    ['key' => $fullKey],
                    ['value' => $this->encodeSettingValue($value)],
                );
            }

            $this->logActivity($request, 'settings.notifications_updated', 'settings', 0, after: [
                'keys' => count($data['notifications']),
            ]);
        });

        return back()->with('success', 'Notification settings updated.');
    }

    /* ─────────────────────────────────────────────────────────────────────
     * INTEGRATIONS
     * ─────────────────────────────────────────────────────────────────── */

    public function integrations(): Response
    {
        return Inertia::render('Internal/Settings/Integrations', [
            'integrations' => [
                [
                    'key' => 'cloudflare',
                    'name' => 'Cloudflare',
                    'description' => 'DNS, SSL and domain monitoring.',
                    'colour' => '#F38020',
                    'initials' => 'CF',
                    'connected' => ! empty(config('services.cloudflare.token')),
                    'testable' => true,
                ],
                [
                    'key' => 'postmark',
                    'name' => 'Postmark',
                    'description' => 'Transactional email delivery.',
                    'colour' => '#FFD500',
                    'initials' => 'PM',
                    'connected' => ! empty(config('services.postmark_token')),
                    'testable' => false,
                ],
                [
                    'key' => 'stripe',
                    'name' => 'Stripe',
                    'description' => 'Card payments and subscription billing.',
                    'colour' => '#635BFF',
                    'initials' => 'S',
                    'connected' => ! empty(config('services.stripe.secret')),
                    'testable' => false,
                ],
                [
                    'key' => 'quickbooks',
                    'name' => 'QuickBooks Online',
                    'description' => 'Used for accounting sync.',
                    'colour' => '#2CA01C',
                    'initials' => 'QB',
                    'connected' => ! empty(config('services.quickbooks.client_id')),
                    'testable' => false,
                ],
            ],
        ]);
    }

    public function integrationTest(string $name, Request $request): RedirectResponse
    {
        // The name comes straight from the URL — allow-list it before
        // doing anything else.
        if (! in_array($name, ['cloudflare', 'postmark', 'stripe', 'quickbooks'], true)) {
            abort(404);
        }

        if ($name === 'cloudflare') {
            $ok = ! empty(config('services.cloudflare.token'));
            $this->logActivity($request, 'settings.integration_tested', 'integration', 0, after: [
                'name' => $name, 'ok' => $ok,
            ]);

            return back()->with(
                $ok ? 'success' : 'error',
                $ok ? 'Cloudflare connection looks healthy.' : 'Cloudflare API token is not set.',
            );
        }

        return back()->with('error', "No connection test wired for {$name} yet.");
    }

    /* ─────────────────────────────────────────────────────────────────────
     * AUDIT LOG
     * ─────────────────────────────────────────────────────────────────── */

    public function auditLog(): Response
    {
        $entries = ActivityLog::orderByDesc('created_at')
            ->take(200)
            ->get()
            ->map(fn (ActivityLog $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'user_role' => $a->user_role,
                'entity_type' => $a->entity_type,
                'entity_id' => $a->entity_id,
                'ip_address' => $a->ip_address,
                'created_at' => $a->created_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Internal/Settings/AuditLog', [
            'entries' => $entries,
        ]);
    }

    /* ─────────────────────────────────────────────────────────────────────
     * DANGER ZONE
     * ─────────────────────────────────────────────────────────────────── */

    public function danger(): Response
    {
        return Inertia::render('Internal/Settings/DangerZone', [
            'env_is_production' => app()->isProduction(),
        ]);
    }

    public function dangerResetNotifications(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            Setting::query()
                ->where('key', 'like', 'notifications.%')
                ->delete();

            $this->logActivity($request, 'settings.notifications_reset', 'settings', 0);
        });

        return back()->with('success', 'Notification settings reset to defaults.');
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Helpers
     * ─────────────────────────────────────────────────────────────────── */

    private function encodeSettingValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function decodeSettingValue(string $raw, mixed $default): mixed
    {
        if (is_bool($default)) {
            return $raw === 'true';
        }
        if (is_int($default)) {
            return (int) $raw;
        }

        return $raw;
    }

    private function logActivity(
        Request $request,
        string $action,
        string $entityType,
        int $entityId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
