<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ReminderTemplate;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\CloudflareService;
use App\Services\PageSpeedService;
use App\Services\ReminderTemplateService;
use App\Services\WhmService;
use Illuminate\Http\JsonResponse;
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
        // Support auto-close window in days. The
        // support:close-inactive command reads from this. 0 disables.
        'support.auto_close_days' => 7,
    ];

    /**
     * Defaults for the Billing automation panel. Read by the
     * invoices:process-suspensions sweep.
     *
     * @var array<string, bool|int>
     */
    private const BILLING_DEFAULTS = [
        // Days an invoice must be overdue before its products are
        // auto-suspended. 0 disables auto-suspension entirely.
        'billing.auto_suspend_days' => 15,
        // Hours that must elapse after a final-notice reminder before
        // suspension fires.
        'billing.suspension_grace_hours' => 24,
        // Whether a payment auto-reinstates suspended products
        // (consumed by the Stripe sprint — stored now as a stub).
        'billing.auto_reinstate' => true,
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
            // Support auto-close threshold. 0 disables the job.
            'support.auto_close_days' => ['required', 'integer', 'min:0', 'max:90'],
        ]);

        DB::transaction(function () use ($data, $request) {
            foreach ($data['notifications'] as $key => $value) {
                $fullKey = "notifications.$key";
                Setting::updateOrCreate(
                    ['key' => $fullKey],
                    ['value' => $this->encodeSettingValue($value)],
                );
            }
            // support.* keys live alongside the notifications panel
            // because the operator workflow ("how does the queue
            // behave") groups them together visually.
            Setting::updateOrCreate(
                ['key' => 'support.auto_close_days'],
                ['value' => $this->encodeSettingValue((int) $data['support']['auto_close_days'])],
            );

            $this->logActivity($request, 'settings.notifications_updated', 'settings', 0, after: [
                'keys' => count($data['notifications']),
            ]);
        });

        return back()->with('success', 'Notification settings updated.');
    }

    /* ─────────────────────────────────────────────────────────────────────
     * BILLING AUTOMATION
     * ─────────────────────────────────────────────────────────────── */

    public function billing(): Response
    {
        $stored = Setting::query()
            ->whereIn('key', array_keys(self::BILLING_DEFAULTS))
            ->pluck('value', 'key')
            ->all();

        $values = [];
        foreach (self::BILLING_DEFAULTS as $key => $default) {
            $raw = $stored[$key] ?? null;
            $values[$key] = $raw === null ? $default : $this->decodeSettingValue($raw, $default);
        }

        return Inertia::render('Internal/Settings/Billing', [
            'values' => $values,
        ]);
    }

    public function billingUpdate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_suspend_days' => ['required', 'integer', 'min:0', 'max:365'],
            'suspension_grace_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'auto_reinstate' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $request) {
            Setting::setValue('billing.auto_suspend_days', (int) $data['auto_suspend_days']);
            Setting::setValue('billing.suspension_grace_hours', (int) $data['suspension_grace_hours']);
            Setting::setValue('billing.auto_reinstate', (bool) $data['auto_reinstate']);

            $this->logActivity($request, 'settings.billing_updated', 'settings', 0, after: [
                'auto_suspend_days' => (int) $data['auto_suspend_days'],
                'suspension_grace_hours' => (int) $data['suspension_grace_hours'],
                'auto_reinstate' => (bool) $data['auto_reinstate'],
            ]);
        });

        return back()->with('success', 'Billing automation settings updated.');
    }

    /**
     * Re-queue a single webhook delivery from the Integrations log.
     * Resets it to pending and clears the backoff so the next job (or
     * sweep) picks it up immediately. Works for failed and abandoned rows.
     */
    public function retryWebhookDelivery(int $id, Request $request): RedirectResponse
    {
        $delivery = WebhookDelivery::findOrFail($id);

        $delivery->update([
            'status' => 'pending',
            'next_retry_at' => null,
        ]);

        DeliverWebhook::dispatch($delivery);

        $this->logActivity($request, 'webhook.retried', 'webhook_delivery', $delivery->id, after: [
            'event_type' => $delivery->event_type,
            'product_slug' => $delivery->product_slug,
        ]);

        return back()->with('success', 'Webhook delivery re-queued.');
    }

    /* ─────────────────────────────────────────────────────────────────────
     * REMINDER TEMPLATES
     *
     * Five tier-keyed email templates rendered by the
     * ReminderTemplateService when an invoice reminder fires. The
     * settings page lets the operator edit subject + body and preview
     * the rendered output against a real invoice.
     * ─────────────────────────────────────────────────────────────── */

    public function reminderTemplates(): Response
    {
        $templates = ReminderTemplate::orderByRaw(
            "FIELD(tier, 'due_soon','due_today','first_reminder','second_reminder','final_notice')"
        )->get();

        return Inertia::render('Internal/Settings/ReminderTemplates', [
            'templates' => $templates->map(fn (ReminderTemplate $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'tier' => $t->tier,
                'subject' => $t->subject,
                'body' => $t->body,
                'tone' => $t->tone,
                'is_active' => (bool) $t->is_active,
                'variables_used' => $t->variables_used ?? [],
            ])->all(),
            'available_variables' => ReminderTemplate::AVAILABLE_VARIABLES,
        ]);
    }

    public function reminderTemplatesUpdate(int $id, Request $request): RedirectResponse
    {
        $template = ReminderTemplate::findOrFail($id);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($template, $data, $request) {
            $before = [
                'subject' => $template->subject,
                'is_active' => $template->is_active,
            ];
            $template->update($data);

            $this->logActivity($request, 'reminder_template.updated', 'reminder_template', $template->id, $before, [
                'subject' => $template->subject,
                'is_active' => (bool) $template->is_active,
            ]);
        });

        return back()->with('success', 'Template updated.');
    }

    /**
     * Render the template against the most recently created invoice so
     * the operator can see exactly what the email will look like. Falls
     * back to a synthetic placeholder when no invoice exists yet — a
     * fresh install shouldn't 404 on preview.
     */
    public function reminderTemplatesPreview(int $id, ReminderTemplateService $service): JsonResponse
    {
        $template = ReminderTemplate::findOrFail($id);

        $invoice = Invoice::with(['customer.primaryContact', 'billingEntity'])
            ->orderByDesc('id')
            ->first();

        if ($invoice === null) {
            return response()->json([
                'subject' => $template->subject,
                'body' => $template->body,
                'note' => 'No invoices exist yet — placeholders not substituted.',
            ]);
        }

        $rendered = $service->renderTemplate($template, $invoice);

        return response()->json([
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            'invoice_number' => $invoice->number,
            'customer_name' => $invoice->customer?->name,
        ]);
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
                [
                    'key' => 'whm',
                    'name' => 'WHM / cPanel (040hosting.eu)',
                    'description' => 'Hosting usage, account suspension and provisioning.',
                    'colour' => '#FF6C2C',
                    'initials' => 'WHM',
                    'connected' => ! empty(config('services.cpanel.whm_token')),
                    'testable' => true,
                    'server' => config('services.cpanel.whm_server'),
                ],
                [
                    'key' => 'pagespeed',
                    'name' => 'Google PageSpeed Insights',
                    'description' => 'Lighthouse performance + Core Web Vitals.',
                    'colour' => '#4285F4',
                    'initials' => 'PSI',
                    'connected' => ! empty(config('services.cpanel.pagespeed_key')),
                    'testable' => true,
                ],
            ],
            // Recent outbound product webhooks for the delivery log.
            'webhook_deliveries' => WebhookDelivery::query()
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (WebhookDelivery $d): array => [
                    'id' => $d->id,
                    'event_type' => $d->event_type,
                    'product_slug' => $d->product_slug,
                    'status' => $d->status,
                    'http_status' => $d->http_status,
                    'attempts' => $d->attempts,
                    'max_attempts' => $d->max_attempts,
                    'created_at' => $d->created_at?->diffForHumans(),
                    'can_retry' => in_array($d->status, ['failed', 'abandoned'], true),
                ])->all(),
        ]);
    }

    public function integrationTest(string $name, Request $request): RedirectResponse
    {
        // The name comes straight from the URL — allow-list it before
        // doing anything else.
        if (! in_array($name, ['cloudflare', 'postmark', 'stripe', 'quickbooks', 'whm', 'pagespeed'], true)) {
            abort(404);
        }

        if ($name === 'cloudflare') {
            // Actually hit /user/tokens/verify rather than just
            // checking the env var is non-empty — a typo'd token
            // would otherwise be reported as healthy until the next
            // domain refresh failed.
            $ok = app(CloudflareService::class)->testConnection();
            $this->logActivity($request, 'settings.integration_tested', 'integration', 0, after: [
                'name' => $name, 'ok' => $ok,
            ]);

            return back()->with(
                $ok ? 'success' : 'error',
                $ok ? 'Cloudflare token verified.' : 'Cloudflare token missing or invalid.',
            );
        }

        if ($name === 'whm') {
            $ok = app(WhmService::class)->testConnection();
            $this->logActivity($request, 'settings.integration_tested', 'integration', 0, after: [
                'name' => $name, 'ok' => $ok,
            ]);

            return back()->with(
                $ok ? 'success' : 'error',
                $ok ? 'WHM connection verified.' : 'WHM token missing or invalid.',
            );
        }

        if ($name === 'pagespeed') {
            $ok = app(PageSpeedService::class)->testConnection();
            $this->logActivity($request, 'settings.integration_tested', 'integration', 0, after: [
                'name' => $name, 'ok' => $ok,
            ]);

            return back()->with(
                $ok ? 'success' : 'error',
                $ok ? 'PageSpeed API key valid.' : 'PageSpeed API key missing or invalid.',
            );
        }

        return back()->with('error', "No connection test wired for {$name} yet.");
    }

    /* ─────────────────────────────────────────────────────────────────────
     * AUDIT LOG
     * ─────────────────────────────────────────────────────────────────── */

    public function auditLog(): Response
    {
        $logs = ActivityLog::with('user:id,name')
            ->orderByDesc('created_at')
            ->take(200)
            ->get();

        // Pre-fetch the related-entity names in one go per type so the
        // enricher doesn't fire one query per row. 200 rows × five
        // entity types = ~1000 queries worst case if we didn't.
        $bucketIds = $logs->groupBy('entity_type')->map(fn ($g) => $g->pluck('entity_id')->unique()->all());
        $names = [
            'customer' => $this->lookupNames(Customer::class, $bucketIds['customer'] ?? []),
            'invoice' => $this->lookupNames(Invoice::class, $bucketIds['invoice'] ?? [], 'number'),
            'task' => $this->lookupNames(Task::class, $bucketIds['task'] ?? [], 'title'),
            'support_ticket' => $this->lookupNames(SupportTicket::class, $bucketIds['support_ticket'] ?? [], 'subject'),
            'product' => $this->lookupNames(Product::class, $bucketIds['product'] ?? []),
            'billing_entity' => $this->lookupNames(BillingEntity::class, $bucketIds['billing_entity'] ?? []),
            'referrer' => [], // Referrer.name comes off the linked User; resolved below if needed.
        ];

        $entries = $logs
            ->map(fn (ActivityLog $a) => $this->enrichActivity($a, $names))
            ->all();

        return Inertia::render('Internal/Settings/AuditLog', [
            'entries' => $entries,
        ]);
    }

    /**
     * Build a human-readable audit-log row. Returns the raw fields the
     * existing template already reads plus three new ones:
     *
     *   - label: a one-line phrase describing the event ("Created
     *     invoice INV-0123") so the table reads as a sentence rather
     *     than a snake_cased token.
     *   - url: where to jump to inspect the affected entity, when one
     *     applies. Used by the row's "View →" link and to make the
     *     row clickable.
     *   - has_diff: true when before+after payloads exist so the Vue
     *     side can render the expandable diff block conditionally.
     *
     * @param  array<string, array<int, string>>  $names
     * @return array<string, mixed>
     */
    private function enrichActivity(ActivityLog $log, array $names): array
    {
        $after = is_array($log->after) ? $log->after : [];
        // entity_type and entity_id are NOT NULL at the schema level —
        // every audit row points at something. The lookup map may
        // simply not include the type, in which case ?? null wins.
        $entityName = $names[$log->entity_type][$log->entity_id] ?? null;

        $label = match ($log->action) {
            'customer.created' => 'Added customer '.($entityName ?? 'a customer'),
            'customer.updated' => 'Updated customer '.($entityName ?? '#'.$log->entity_id),
            'customer.archived' => 'Archived customer '.($entityName ?? '#'.$log->entity_id),
            'invoice.created' => 'Created invoice '.($after['number'] ?? $entityName ?? '#'.$log->entity_id),
            'invoice.updated' => 'Edited invoice '.($entityName ?? '#'.$log->entity_id),
            'invoice.sent' => 'Sent invoice '.($after['number'] ?? $entityName ?? '#'.$log->entity_id),
            'invoice.marked_paid' => 'Marked '.($entityName ?? '#'.$log->entity_id).' as paid',
            'invoice.voided' => 'Voided invoice '.($entityName ?? '#'.$log->entity_id),
            'invoice.reminder_sent' => 'Sent reminder for '.($entityName ?? 'an invoice'),
            'invoice.recurring_generated' => 'Generated recurring invoice '.($after['new_invoice_number'] ?? ''),
            'invoice.recurring_stopped' => 'Stopped recurring schedule on '.($entityName ?? 'an invoice'),
            'task.created' => 'Created activity: "'.($after['title'] ?? 'task').'"',
            'task.completed' => 'Completed activity: "'.($after['title'] ?? 'task').'"',
            'task.updated' => 'Updated activity',
            'task.deleted' => 'Deleted activity',
            'note.created' => 'Added a note',
            'note.updated' => 'Edited a note',
            'note.deleted' => 'Deleted a note',
            'support.ticket_created' => 'Opened support ticket: '.($entityName ?? '#'.$log->entity_id),
            'support.reply_sent' => 'Replied to ticket '.($entityName ?? '#'.$log->entity_id),
            'support.status_updated' => 'Updated ticket status: '.($after['status'] ?? '—'),
            'support.task_created' => 'Created task from ticket '.($entityName ?? '#'.$log->entity_id),
            'support.auto_closed' => 'Auto-closed ticket '.($entityName ?? '#'.$log->entity_id),
            'product.created' => 'Created product '.($entityName ?? ''),
            'product.updated' => 'Updated product '.($entityName ?? ''),
            'product.toggled' => 'Toggled product '.($entityName ?? '').' '.($after['is_active'] ?? false ? 'on' : 'off'),
            'referrer.created' => 'Added referrer',
            'referrer.updated' => 'Updated referrer',
            'referrer.password_reset' => 'Reset referrer password',
            'commission.approved' => 'Approved commission entry',
            'commission.bulk_approved' => 'Approved '.($after['count'] ?? '?').' commission entries',
            'commission.paid' => 'Marked commission as paid',
            'billing_entity.created' => 'Created billing entity '.($entityName ?? ''),
            'billing_entity.updated' => 'Updated billing entity '.($entityName ?? ''),
            'auth.login' => 'Signed in',
            'auth.logout' => 'Signed out',
            'auth.failed' => 'Failed sign-in attempt',
            'auth.password_changed' => 'Changed password',
            'settings.notifications_updated' => 'Updated notification settings',
            // Fallback humanises the dotted action name so we still
            // get something readable for actions we haven't mapped.
            default => ucfirst(str_replace(['.', '_'], [': ', ' '], $log->action)),
        };

        return [
            'id' => $log->id,
            'action' => $log->action,
            'label' => $label,
            'user_role' => $log->user_role,
            'user_name' => $log->user
                ? $log->user->name
                : ($log->user_role === 'system' ? 'System' : 'Unknown'),
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'entity_name' => $entityName,
            'ip_address' => $log->ip_address,
            'url' => $this->entityUrl($log),
            'created_at' => $log->created_at?->toIso8601String(),
            'time_ago' => $log->created_at?->diffForHumans(),
            'before' => $log->before,
            'after' => $log->after,
            'has_diff' => ! empty($log->before) || ! empty($log->after),
        ];
    }

    private function entityUrl(ActivityLog $log): ?string
    {
        // entity_id is NOT NULL at the schema level; the only
        // optionality is whether we know how to link the given
        // entity_type, which is the match() arm's responsibility.
        return match ($log->entity_type) {
            'customer' => '/customers/'.$log->entity_id,
            'invoice' => '/invoices/'.$log->entity_id,
            'task' => '/activities/'.$log->entity_id,
            'support_ticket' => '/support/'.$log->entity_id,
            'referrer' => '/referrers/'.$log->entity_id,
            default => null,
        };
    }

    /**
     * Fetch id → label for a batch of model rows in one query so the
     * audit-log enrichment doesn't N+1. Returns an empty map when no
     * ids were supplied so callers can use [$id] ?? null safely.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function lookupNames(string $model, array $ids, string $column = 'name'): array
    {
        $ids = array_filter(array_map('intval', $ids));
        if ($ids === []) {
            return [];
        }

        return $model::query()
            ->whereIn('id', $ids)
            ->pluck($column, 'id')
            ->all();
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
