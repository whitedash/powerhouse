<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Project;
use App\Models\Referrer;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    private const ACTIVITY_LABELS = [
        'customer.created' => 'New customer',
        'customer.updated' => 'Customer updated',
        'customer.archived' => 'Customer archived',
        'customer.note_added' => 'Note added',
        'customer.task_added' => 'Task added',
        'task.created' => 'Task created',
        'task.completed' => 'Task completed',
        'invoice.created' => 'Invoice created',
        'invoice.updated' => 'Invoice updated',
        'invoice.sent' => 'Invoice sent',
        'invoice.marked_paid' => 'Invoice paid',
        'invoice.voided' => 'Invoice voided',
        'invoice.pdf_downloaded' => 'PDF downloaded',
        'invoice.pdf_previewed' => 'PDF previewed',
        'invoice.reminder_sent' => 'Reminder sent',
        'invoice.reminders_paused' => 'Auto-reminders paused',
        'invoice.reminders_resumed' => 'Auto-reminders resumed',
        'auth.login' => 'Staff login',
        'auth.logout' => 'Staff logout',
        'auth.failed' => 'Failed login attempt',
        'auth.password_reset' => 'Password reset',
        'billing_entity.created' => 'Billing entity created',
        'billing_entity.updated' => 'Billing entity updated',
        'domain.customer_removed' => 'Domain updated',
        'security.mass_export_detected' => 'Security alert',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        // The "Refresh" button on the Platform health card triggers a
        // partial Inertia reload with this query param so the operator
        // can bust the 5-minute health cache on demand without forcing
        // the whole dashboard to rerun every query.
        if ($request->boolean('refresh_health')) {
            Cache::forget('dashboard.platform_health');
        }

        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $prevMonthStart = $monthStart->copy()->subMonthNoOverflow();
        $prevMonthEnd = $monthEnd->copy()->subMonthNoOverflow();

        // buildAttention returns every red/amber item across invoices,
        // tickets, trials and tasks. The panel only previews the first 8
        // (red first) — the modal renders the full list grouped by type.
        $attention = $this->buildAttention($now, $request->user()?->id);

        return Inertia::render('Internal/Dashboard', [
            'greeting' => $this->buildGreeting($request),
            'today' => $now->format('l, j F Y'),
            'stats' => $this->buildStats($now),
            'products' => $this->buildProducts(),
            'attention' => $attention,
            'attention_count' => count($attention),
            'activity' => $this->buildActivity(),
            'tasks' => $this->buildTasks($request),
            'this_month' => $this->buildThisMonth($monthStart, $prevMonthStart, $prevMonthEnd),
            'referrers' => $this->buildReferrers($monthStart),
            'total_pending_commissions' => (float) CommissionLedger::where('status', 'pending')
                ->sum('commission_amount'),
            'platform_health' => $this->buildPlatformHealth(),
            // Slim payloads for the New-task slide-over (linkable customer
            // + assignable user select). Active customers only; archived
            // wouldn't be sensible to attach a new task to.
            'customers' => Customer::whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'assignable_users' => User::whereIn('role', ['super_admin', 'staff'])
                ->orderBy('name')
                ->get(['id', 'name', 'role', 'avatar_colour'])
                ->all(),
            // Map of customer_id => [{id,name}] for the activity
            // slide-over's contact dropdown — populated client-side
            // when the operator picks a customer. Loaded once at
            // page level instead of an extra fetch per customer pick.
            'contacts_by_customer' => $this->buildContactsByCustomer(),
        ]);
    }

    /**
     * Lightweight CSV export of the dashboard headline numbers. Streamed
     * via response()->streamDownload() so we don't materialise the whole
     * file in memory — even for a thin report, sticking to the streaming
     * pattern means a heavier export later can swap in without changing
     * the route signature.
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $stats = $this->buildStats($now);
        $thisMonth = $this->buildThisMonth(
            $monthStart,
            $monthStart->copy()->subMonthNoOverflow(),
            $monthStart->copy()->subMonthNoOverflow()->endOfMonth(),
        );
        $products = $this->buildProducts();

        $filename = 'powerhouse-report-'.$now->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($now, $stats, $thisMonth, $products) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Report generated', $now->format('Y-m-d H:i T')]);
            fputcsv($out, []);

            fputcsv($out, ['Headline']);
            fputcsv($out, ['Total customers', $stats['total_customers'] ?? 0]);
            fputcsv($out, ['MRR (£)', number_format((float) ($stats['mrr'] ?? 0), 2, '.', '')]);
            fputcsv($out, ['ARR (£)', number_format((float) ($stats['mrr'] ?? 0) * 12, 2, '.', '')]);
            fputcsv($out, ['Pending invoices (count)', $stats['pending_invoices_count'] ?? 0]);
            fputcsv($out, ['Pending invoices (£)', number_format((float) ($stats['pending_invoices_amount'] ?? 0), 2, '.', '')]);
            fputcsv($out, ['Open tickets', $stats['open_tickets_count'] ?? 0]);
            fputcsv($out, ['SLA-breached tickets', $stats['overdue_sla_count'] ?? 0]);
            fputcsv($out, []);

            fputcsv($out, ['This month']);
            fputcsv($out, ['New customers', $thisMonth['new_customers'] ?? 0]);
            fputcsv($out, ['Churned customers', $thisMonth['churned_customers'] ?? 0]);
            fputcsv($out, ['Revenue collected (£)', number_format((float) ($thisMonth['revenue_collected'] ?? 0), 2, '.', '')]);
            fputcsv($out, []);

            fputcsv($out, ['Active by product']);
            fputcsv($out, ['Product', 'Active customers', 'MRR (£)']);
            foreach ($products as $p) {
                fputcsv($out, [
                    $p['name'] ?? '',
                    $p['customer_count'] ?? 0,
                    number_format((float) ($p['mrr'] ?? 0), 2, '.', ''),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStats(Carbon $now): array
    {
        return [
            'total_customers' => Cache::remember(
                'dash.total_customers',
                120,
                fn () => Customer::whereNull('archived_at')->count(),
            ),
            // mrr_contribution amortises across interval_count +
            // interval_unit so a quarterly £75 sub reports £25 here
            // rather than the full bill amount.
            'mrr' => (float) Cache::remember(
                'dash.mrr',
                120,
                fn () => (float) CustomerProduct::where('status', 'active')
                    ->with('planPrice')
                    ->get()
                    ->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution),
            ),
            'pending_invoices_count' => Invoice::whereIn('status', ['sent', 'overdue'])->count(),
            'pending_invoices_amount' => (float) Invoice::whereIn('status', ['sent', 'overdue'])->sum('total'),
            'overdue_sla_count' => SupportTicket::whereNotIn('status', ['resolved', 'closed'])
                ->whereNotNull('sla_breach_at')
                ->where('sla_breach_at', '<', $now)
                ->count(),
            'open_tickets_count' => SupportTicket::whereNotIn('status', ['resolved', 'closed'])->count(),
            'expiring_30_count' => Domain::whereNotNull('expiry_date')
                ->where('expiry_date', '<=', $now->copy()->addDays(30))
                ->count(),
            'expiring_critical_count' => Domain::whereNotNull('expiry_date')
                ->where('expiry_date', '<=', $now->copy()->addDays(7))
                ->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildProducts(): array
    {
        return Product::orderBy('sort_order')
            ->get()
            ->map(function (Product $p): array {
                $activeQuery = CustomerProduct::where('product_id', $p->id)->where('status', 'active');

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'description' => $p->description,
                    'icon_colour' => $p->icon_colour,
                    'is_active' => $p->is_active,
                    'is_coming_soon' => $p->is_coming_soon,
                    'customer_count' => $p->is_coming_soon ? 0 : (clone $activeQuery)->count(),
                    // planPrice eager-loaded so mrr_contribution can use
                    // the canonical price-row math under
                    // Model::preventLazyLoading().
                    'mrr' => $p->is_coming_soon ? 0.0 : (float) (clone $activeQuery)
                        ->with('planPrice')
                        ->get()
                        ->sum(fn (CustomerProduct $cp): float => $cp->mrr_contribution),
                ];
            })
            ->all();
    }

    /**
     * Build every "needs attention" item across the platform.
     *
     * Sources: overdue invoices, SLA-breached tickets, trials ending in
     * 7 days, overdue tasks assigned to the current operator. Items are
     * sorted red-first so the panel preview (first 8) leads with the
     * highest-priority work. The modal renders the full list grouped
     * by type — no take() cap is applied here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildAttention(Carbon $now, ?int $userId): array
    {
        /** @var Collection<int, array<string, mixed>> $items */
        $items = collect();

        // Overdue invoices — red. We send all of them; the panel takes
        // the first 8 across all sources and the modal lists everything.
        Invoice::where('status', 'overdue')
            ->with('customer:id,name')
            ->orderBy('due_date')
            ->get()
            ->each(function (Invoice $inv) use ($now, $items) {
                $days = $inv->due_date
                    ? (int) abs($now->copy()->startOfDay()->diffInDays($inv->due_date->copy()->startOfDay(), false))
                    : 0;
                $items->push([
                    'type' => 'invoice',
                    'priority' => 'red',
                    'title' => 'Invoice '.$inv->number.' overdue '.$days.' '.($days === 1 ? 'day' : 'days'),
                    'sub' => $inv->customer->name.' · £'.number_format((float) $inv->total, 2),
                    'action' => 'Chase →',
                    'href' => '/invoices/'.$inv->id,
                ]);
            });

        // SLA-breached tickets — red.
        SupportTicket::whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('sla_breach_at')
            ->where('sla_breach_at', '<', $now)
            ->with('customer:id,name')
            ->orderBy('sla_breach_at')
            ->get()
            ->each(function (SupportTicket $t) use ($items) {
                $items->push([
                    'type' => 'ticket',
                    'priority' => 'red',
                    'title' => 'SLA breached: '.Str::limit((string) $t->subject, 40),
                    'sub' => $t->customer->name,
                    'action' => 'View →',
                    'href' => '/support/'.$t->id,
                ]);
            });

        // Trials ending in the next 7 days — amber.
        CustomerProduct::where('status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now->copy()->addDays(7))
            ->with(['customer:id,name', 'product:id,name'])
            ->orderBy('trial_ends_at')
            ->get()
            ->each(function (CustomerProduct $cp) use ($items) {
                $items->push([
                    'type' => 'trial',
                    'priority' => 'amber',
                    'title' => $cp->product->name.' trial ending',
                    'sub' => $cp->customer->name.' · '.($cp->trial_ends_at?->format('d M') ?? '—'),
                    'action' => 'Upgrade →',
                    'href' => '/customers/'.$cp->customer_id,
                ]);
            });

        // Overdue projects — red. Two cap so we don't drown out
        // invoice + ticket pressure; the Projects page is the full
        // list. Internal projects (no customer) fall back to a
        // generic sub-label.
        Project::where('status', 'active')
            ->whereNull('archived_at')
            ->whereNotNull('due_date')
            ->where('due_date', '<', $now)
            ->with('customer:id,name')
            ->orderBy('due_date')
            ->take(2)
            ->get()
            ->each(function (Project $p) use ($items) {
                $items->push([
                    'type' => 'project',
                    'priority' => 'red',
                    'title' => 'Project overdue: '.Str::limit($p->title, 40),
                    // customer_id is nullable on projects (internal vs
                    // customer-attached); branch on the FK rather than
                    // a nullsafe chain to keep phpstan quiet.
                    'sub' => $p->customer_id !== null ? $p->customer->name : 'Internal project',
                    'action' => 'Open →',
                    'href' => '/projects/'.$p->id,
                ]);
            });

        // Blocked tasks assigned to the operator — red. These are
        // work that can't proceed without someone unblocking; the
        // sub-label exposes the reason inline.
        if ($userId !== null) {
            Task::where('status', 'blocked')
                ->where('assigned_to', $userId)
                ->take(2)
                ->get()
                ->each(function (Task $t) use ($items) {
                    $items->push([
                        'type' => 'task',
                        'priority' => 'red',
                        'title' => 'Blocked: '.Str::limit($t->title, 40),
                        'sub' => Str::limit($t->blocked_reason ?? 'No reason given', 80),
                        'action' => 'View →',
                        'href' => '/activities/'.$t->id,
                    ]);
                });
        }

        // New leads — amber. Anything created in the last 48h that
        // hasn't moved off 'new' yet is a fair signal someone needs
        // to make first contact. Capped at 2 so the panel doesn't
        // drown out invoices and tickets on busy weeks.
        Lead::where('status', 'new')
            ->whereNull('customer_id')
            ->where('created_at', '>=', $now->copy()->subDays(2))
            ->orderByDesc('created_at')
            ->take(2)
            ->get()
            ->each(function (Lead $lead) use ($items) {
                $sub = $lead->company !== null
                    ? $lead->company.' · '.str_replace('_', ' ', $lead->source)
                    : str_replace('_', ' ', $lead->source).' · '.($lead->created_at?->diffForHumans() ?? 'just now');
                $items->push([
                    'type' => 'lead',
                    'priority' => 'amber',
                    'title' => 'New lead: '.$lead->name,
                    'sub' => $sub,
                    'action' => 'View →',
                    'href' => '/leads/'.$lead->id,
                ]);
            });

        // Overdue tasks (assigned to the current operator) — amber.
        // Scoped to the operator because the dashboard is personal:
        // someone else's overdue task isn't *your* problem.
        if ($userId !== null) {
            Task::whereNotIn('status', ['complete', 'cancelled'])
                ->whereNotNull('due_at')
                ->where('due_at', '<', $now)
                ->where('assigned_to', $userId)
                ->with('customer:id,name')
                ->orderBy('due_at')
                ->get()
                ->each(function (Task $t) use ($items) {
                    $items->push([
                        'type' => 'task',
                        'priority' => 'amber',
                        'title' => $t->title,
                        // Tasks may or may not have a customer; without one
                        // we fall back to a dash. Customer relation only
                        // resolves when customer_id is set.
                        'sub' => $t->customer_id !== null ? $t->customer->name : '—',
                        'action' => 'View →',
                        'href' => $t->customer_id !== null ? '/customers/'.$t->customer_id : '/',
                    ]);
                });
        }

        // Stable sort: red before amber, original push order preserved
        // within each priority. values() resets the keys so Inertia
        // serialises as a JSON array, not an object.
        return $items
            ->sortBy(fn (array $i): int => $i['priority'] === 'red' ? 0 : 1, SORT_REGULAR, false)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildActivity(): array
    {
        return ActivityLog::orderByDesc('created_at')
            ->take(8)
            ->get()
            ->map(function (ActivityLog $a): array {
                $type = explode('.', $a->action)[0];

                return [
                    'action' => $a->action,
                    'label' => self::ACTIVITY_LABELS[$a->action] ?? $a->action,
                    'type' => $type,
                    'after' => $a->after,
                    'entity_type' => $a->entity_type,
                    'entity_id' => $a->entity_id,
                    'user_role' => $a->user_role,
                    'created_at' => $a->created_at?->toIso8601String(),
                    'time_ago' => $a->created_at?->diffForHumans(),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTasks(Request $request): array
    {
        $userId = $request->user()?->id;
        if ($userId === null) {
            return [];
        }

        return Task::where('assigned_to', $userId)
            // After the PM sprint, "open" became any non-terminal
            // status — the dashboard sidebar list still wants
            // anything actionable on this operator's plate.
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->with(['customer:id,name', 'contact:id,name'])
            ->orderByRaw('is_pinned DESC, due_at IS NULL, due_at ASC')
            ->take(5)
            ->get()
            ->map(fn (Task $t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'type' => $t->type,
                'type_icon' => $t->type_icon,
                'type_colour' => $t->type_colour,
                'priority' => $t->priority,
                'description' => $t->description ? Str::limit($t->description, 80) : null,
                'due_at' => $t->due_at?->toIso8601String(),
                'is_overdue' => $t->is_overdue,
                'is_due_today' => $t->due_at && $t->due_at->isToday(),
                'is_pinned' => $t->is_pinned,
                'contact_name' => $t->contact?->name,
                'customer' => $t->customer
                    ? ['id' => $t->customer->id, 'name' => $t->customer->name]
                    : null,
            ])
            ->all();
    }

    /**
     * @return array<int, array<int, array{id: int, name: string}>>
     */
    private function buildContactsByCustomer(): array
    {
        // Single grouped query rather than N+1 per customer. Trimmed to
        // active customers because we only let the slide-over attach
        // activities to live accounts.
        return Customer::whereNull('archived_at')
            ->with('contacts:id,customer_id,name')
            ->whereHas('contacts')
            ->get(['id'])
            ->mapWithKeys(fn (Customer $c): array => [
                $c->id => $c->contacts->map(fn ($ct): array => [
                    'id' => $ct->id,
                    'name' => $ct->name,
                ])->values()->all(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildThisMonth(Carbon $monthStart, Carbon $prevMonthStart, Carbon $prevMonthEnd): array
    {
        $newCustomers = Customer::where('created_at', '>=', $monthStart)->count();
        $newCustomersPrev = Customer::whereBetween('created_at', [$prevMonthStart, $prevMonthEnd])->count();

        $churned = CustomerProduct::where('status', 'cancelled')
            ->where('cancelled_at', '>=', $monthStart)
            ->count();
        $churnedPrev = CustomerProduct::where('status', 'cancelled')
            ->whereBetween('cancelled_at', [$prevMonthStart, $prevMonthEnd])
            ->count();

        $invoicesRaised = Invoice::where('created_at', '>=', $monthStart)->count();
        $invoicesPaid = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $monthStart)
            ->count();
        $invoicesPaidPct = $invoicesRaised > 0
            ? (int) round($invoicesPaid / $invoicesRaised * 100)
            : 0;

        return [
            'new_customers' => $newCustomers,
            'new_customers_delta' => $newCustomers - $newCustomersPrev,
            'churned' => $churned,
            'churned_delta' => $churned - $churnedPrev,
            'invoices_raised' => $invoicesRaised,
            'invoices_paid' => $invoicesPaid,
            'invoices_paid_pct' => $invoicesPaidPct,
            'commissions_due' => (float) CommissionLedger::where('status', 'pending')->sum('commission_amount'),
            // Avg ticket resolution time is a TODO for the Support sprint —
            // expose 0 so the panel renders without "—" placeholders.
            'avg_resolution_hours' => 0.0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildReferrers(Carbon $monthStart): array
    {
        return Referrer::with('user:id,name,email')
            ->withCount('referrals')
            ->get()
            ->map(fn (Referrer $r): array => [
                'id' => $r->id,
                'name' => $r->user->name,
                'email' => $r->user->email,
                'customer_count' => (int) $r->referrals_count,
                'commission_this_month' => (float) CommissionLedger::where('referrer_id', $r->id)
                    ->where('created_at', '>=', $monthStart)
                    ->sum('commission_amount'),
                'pending_payout' => (float) CommissionLedger::where('referrer_id', $r->id)
                    ->where('status', 'pending')
                    ->sum('commission_amount'),
            ])
            ->all();
    }

    /**
     * Public-facing health snapshot for the dashboard card.
     *
     * Hits each active product surface, the Stripe and Postmark status
     * endpoints (if their credentials are configured), and runs a local
     * SELECT 1 against MySQL for the Powerhouse row. Results are cached
     * for 5 minutes — every dashboard load shouldn't block on 4+
     * outbound HTTP requests with a 5s timeout each.
     *
     * TODO: Store results in service_health_checks table for historical
     * uptime tracking. Sprint: Domain & DNS management.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildPlatformHealth(): array
    {
        return Cache::remember(
            'dashboard.platform_health',
            now()->addMinutes(5),
            function (): array {
                /** @var array<int, array<string, mixed>> $checks */
                $checks = [];

                // Slug → public URL. Product surfaces only get checked if
                // they're active in the products table AND we know where
                // to point at them — silently skip unknown slugs.
                $productUrls = [
                    'maavelus' => 'https://maavelus.co.uk',
                    'maavelus-hospitality' => 'https://maavelus.co.uk',
                    'myorderpad' => 'https://myorderpad.co.uk',
                    'orderpad' => 'https://myorderpad.co.uk',
                    'whitedash' => 'https://whitedash.co.uk',
                ];

                Product::where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'name', 'slug', 'icon_colour'])
                    ->each(function (Product $product) use (&$checks, $productUrls): void {
                        $url = $productUrls[$product->slug] ?? null;
                        if ($url === null) {
                            return;
                        }
                        $check = $this->checkUrl($url);
                        $checks[] = [
                            'name' => $product->name,
                            'type' => 'product',
                            'url' => $url,
                            'status' => $this->classifyStatus($check),
                            'response_ms' => $check['ms'],
                            'checked_at' => now()->format('H:i'),
                            'icon_colour' => $product->icon_colour,
                        ];
                    });

                // Stripe — only check if a secret key is configured so
                // dev environments without keys don't show a misleading
                // red dot.
                $stripeKey = (string) config('services.stripe.secret');
                if ($stripeKey !== '' && str_starts_with($stripeKey, 'sk_')) {
                    $s = $this->checkUrl('https://status.stripe.com/api/v2/status.json');
                    $checks[] = [
                        'name' => 'Stripe',
                        'type' => 'integration',
                        'url' => 'https://stripe.com',
                        'status' => $this->classifyStatus($s),
                        'response_ms' => $s['ms'],
                        'checked_at' => now()->format('H:i'),
                        'icon_colour' => '#635BFF',
                    ];
                }

                // Postmark — same logic, gated on the token being set.
                // Read via config() only; calling env() at runtime
                // returns null once `config:cache` has been run.
                // Falls back to the bare `postmark_token` legacy key
                // some env files still use.
                $postmarkToken = (string) (config('services.postmark.key') ?? config('services.postmark_token'));
                if ($postmarkToken !== '') {
                    $s = $this->checkUrl('https://status.postmarkapp.com/api/v1/status');
                    $checks[] = [
                        'name' => 'Postmark',
                        'type' => 'integration',
                        'url' => 'https://postmarkapp.com',
                        'status' => $this->classifyStatus($s),
                        'response_ms' => $s['ms'],
                        'checked_at' => now()->format('H:i'),
                        'icon_colour' => '#FFDE00',
                    ];
                }

                // Powerhouse self-check — SELECT 1 keeps it cheap. If
                // the DB is down the dashboard wouldn't render at all,
                // but a slow SELECT 1 is still a useful signal.
                $dbOk = true;
                $dbMs = 0;

                try {
                    $start = microtime(true);
                    DB::select('SELECT 1');
                    $dbMs = (int) round((microtime(true) - $start) * 1000);
                } catch (\Throwable $e) {
                    $dbOk = false;
                }
                $checks[] = [
                    'name' => 'Powerhouse',
                    'type' => 'system',
                    'url' => (string) config('app.url'),
                    'status' => $dbOk ? 'healthy' : 'critical',
                    'response_ms' => $dbMs,
                    'checked_at' => now()->format('H:i'),
                    'icon_colour' => '#F59E0B',
                ];

                return $checks;
            },
        );
    }

    /**
     * Single outbound HTTP probe used by buildPlatformHealth().
     *
     * Returns ok=true for any sub-500 response (4xx is "service answered
     * with rate-limit / auth error" — still a live host). 5s timeout.
     *
     * @return array{ok: bool, ms: int, status_code: int}
     */
    private function checkUrl(string $url): array
    {
        $start = microtime(true);

        try {
            $response = Http::timeout(5)
                ->withOptions(['verify' => false])
                ->get($url);
            $ms = (int) round((microtime(true) - $start) * 1000);

            return [
                'ok' => $response->status() < 500,
                'ms' => $ms,
                'status_code' => $response->status(),
            ];
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);

            return [
                'ok' => false,
                'ms' => $ms,
                'status_code' => 0,
            ];
        }
    }

    /**
     * Map a raw probe result to the three-tier status the UI renders.
     *
     * @param  array{ok: bool, ms: int, status_code: int}  $check
     */
    private function classifyStatus(array $check): string
    {
        if (! $check['ok']) {
            return 'critical';
        }
        if ($check['ms'] > 5000) {
            return 'critical';
        }
        if ($check['ms'] > 2000) {
            return 'degraded';
        }

        return 'healthy';
    }

    private function buildGreeting(Request $request): string
    {
        $hour = now()->hour;
        $greeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
        $firstName = explode(' ', (string) ($request->user()->name ?? 'there'))[0];

        return $greeting.', '.$firstName;
    }
}
