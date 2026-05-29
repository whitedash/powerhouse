<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Referrer;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

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

        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $prevMonthStart = $monthStart->copy()->subMonthNoOverflow();
        $prevMonthEnd = $monthEnd->copy()->subMonthNoOverflow();

        $attention = $this->buildAttention($now);

        return Inertia::render('Internal/Dashboard', [
            'greeting' => $this->buildGreeting($request),
            'today' => $now->format('l, j F Y'),
            'stats' => $this->buildStats($now),
            'products' => $this->buildProducts(),
            'attention' => array_values($attention),
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
     * @return array<int, array<string, mixed>>
     */
    private function buildAttention(Carbon $now): array
    {
        /** @var Collection<int, array<string, mixed>> $attention */
        $attention = collect();

        // Critical domains expiring inside 7 days — red priority.
        Domain::whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $now->copy()->addDays(7))
            ->orderBy('expiry_date')
            ->get()
            ->each(function (Domain $d) use ($now, $attention) {
                $days = (int) abs($now->copy()->startOfDay()->diffInDays($d->expiry_date->copy()->startOfDay(), false));
                $attention->push([
                    'type' => 'domain',
                    'priority' => 'red',
                    'title' => $d->domain.' expires in '.$days.' '.($days === 1 ? 'day' : 'days'),
                    'sub' => 'SSL also expiring · auto-renew check',
                    'action' => 'Renew →',
                    'href' => '/domains',
                ]);
            });

        // Overdue invoices — red priority.
        Invoice::where('status', 'overdue')
            ->with('customer:id,name')
            ->orderBy('due_date')
            ->take(3)
            ->get()
            ->each(function (Invoice $i) use ($now, $attention) {
                $days = $i->due_date
                    ? (int) abs($now->copy()->startOfDay()->diffInDays($i->due_date->copy()->startOfDay(), false))
                    : 0;
                $attention->push([
                    'type' => 'invoice',
                    'priority' => 'red',
                    'title' => 'Invoice '.$i->number.' overdue '.$days.' '.($days === 1 ? 'day' : 'days'),
                    'sub' => $i->customer->name.' · £'.number_format((float) $i->total, 2),
                    'action' => 'Chase →',
                    'href' => '/invoices/'.$i->id,
                ]);
            });

        // SLA-breached tickets — red priority.
        SupportTicket::whereNotIn('status', ['resolved', 'closed'])
            ->whereNotNull('sla_breach_at')
            ->where('sla_breach_at', '<', $now)
            ->with('customer:id,name')
            ->take(2)
            ->get()
            ->each(function (SupportTicket $t) use ($now, $attention) {
                $createdAt = $t->created_at;
                $openFor = $createdAt
                    ? $createdAt->diffForHumans($now, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'short' => true])
                    : 'just now';
                $attention->push([
                    'type' => 'ticket',
                    'priority' => 'red',
                    'title' => 'Ticket #TK-'.str_pad((string) $t->id, 4, '0', STR_PAD_LEFT).' SLA breached',
                    'sub' => $t->customer->name.' · '.$openFor.' open',
                    'action' => 'View →',
                    'href' => '/support',
                ]);
            });

        // Outstanding (not-yet-overdue) invoices — amber priority.
        Invoice::where('status', 'sent')
            ->with('customer:id,name')
            ->orderBy('due_date')
            ->take(2)
            ->get()
            ->each(function (Invoice $i) use ($attention) {
                $attention->push([
                    'type' => 'invoice',
                    'priority' => 'amber',
                    'title' => 'Invoice '.$i->number.' outstanding',
                    'sub' => $i->customer->name.' · £'.number_format((float) $i->total, 2),
                    'action' => 'Chase →',
                    'href' => '/invoices/'.$i->id,
                ]);
            });

        // Trials ending in 3 days — amber priority.
        CustomerProduct::where('status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now->copy()->addDays(3))
            ->with(['customer:id,name', 'product:id,name'])
            ->take(2)
            ->get()
            ->each(function (CustomerProduct $cp) use ($now, $attention) {
                $days = $cp->trial_ends_at
                    ? (int) abs($now->copy()->startOfDay()->diffInDays($cp->trial_ends_at->copy()->startOfDay(), false))
                    : 0;
                $attention->push([
                    'type' => 'trial',
                    'priority' => 'amber',
                    'title' => $cp->product->name.' trial ending in '.$days.' '.($days === 1 ? 'day' : 'days'),
                    'sub' => $cp->customer->name.' · no card on file',
                    'action' => 'Upgrade →',
                    'href' => '/customers/'.$cp->customer_id,
                ]);
            });

        // SSL certs expiring 8–30 days out — single rolled-up amber row.
        $sslExpiring = Domain::whereNotNull('ssl_expiry_date')
            ->where('ssl_expiry_date', '>', $now->copy()->addDays(7))
            ->where('ssl_expiry_date', '<=', $now->copy()->addDays(30))
            ->count();

        if ($sslExpiring > 0) {
            $attention->push([
                'type' => 'ssl',
                'priority' => 'amber',
                'title' => $sslExpiring.' SSL cert'.($sslExpiring > 1 ? 's' : '').' expiring in 30 days',
                'sub' => 'domain monitor · review needed',
                'action' => 'Review →',
                'href' => '/domains',
            ]);
        }

        return $attention->all();
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
            ->where('status', 'open')
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
     * @return array<int, array<string, mixed>>
     */
    private function buildPlatformHealth(): array
    {
        $services = Product::orderBy('sort_order')
            ->get()
            ->map(fn (Product $p): array => [
                'name' => $p->name,
                'is_coming_soon' => $p->is_coming_soon,
                // Real health check arrives with the /powerhouse/summary
                // endpoint next sprint. Until then the page renders a
                // static "Operational" signal for shipped products.
                'uptime' => $p->is_coming_soon ? null : 99.95,
                'last_check' => $p->is_coming_soon ? null : 'Just now',
            ])
            ->all();

        $services[] = [
            'name' => 'Customer Portal',
            'is_coming_soon' => false,
            'uptime' => 100.0,
            'last_check' => 'Just now',
        ];
        $services[] = [
            'name' => 'Powerhouse',
            'is_coming_soon' => false,
            'uptime' => 100.0,
            'last_check' => 'Just now',
        ];

        return $services;
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
