<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends Controller
{
    private const STATUSES = ['open', 'in_progress', 'awaiting_customer', 'resolved', 'closed'];

    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /**
     * Hours-from-creation budget per priority. Used to compute
     * sla_breach_at on ticket creation. Source of truth lives here so
     * the spec and the badge logic can't drift.
     *
     * @var array<string, int>
     */
    private const SLA_HOURS = [
        'urgent' => 4,
        'high' => 8,
        'medium' => 24,
        'low' => 72,
    ];

    public function index(Request $request): Response
    {
        $statusFilter = $request->string('status')->toString() ?: null;
        $priorityFilter = $request->string('priority')->toString() ?: null;
        $search = $request->string('search')->toString() ?: null;

        $tickets = SupportTicket::query()
            ->with(['customer:id,name', 'assignedTo:id,name'])
            ->withCount('messages')
            ->when($statusFilter, fn ($q, $s) => $q->where('status', $s))
            ->when($priorityFilter, fn ($q, $p) => $q->where('priority', $p))
            ->when($search, function ($q, $s) {
                $q->where(function ($q2) use ($s) {
                    $q2->where('subject', 'like', "%{$s}%")
                        ->orWhereHas('customer', fn ($q3) => $q3->where('name', 'like', "%{$s}%"));
                });
            })
            // Sort by lifecycle status first (open issues bubble up),
            // then by SLA-breach time so the most urgent unresolved
            // ticket lands at the top.
            ->orderByRaw("CASE status
                WHEN 'open' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'awaiting_customer' THEN 3
                WHEN 'resolved' THEN 4
                WHEN 'closed' THEN 5
                ELSE 6 END")
            ->orderBy('sla_breach_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function (SupportTicket $t): array {
                $breachAt = $t->sla_breach_at;
                $isBreached = $breachAt && $breachAt->isPast();
                $hoursUntilBreach = $breachAt && $breachAt->isFuture()
                    ? $breachAt->diffInHours(now())
                    : null;

                return [
                    'id' => $t->id,
                    'subject' => $t->subject,
                    'status' => $t->status,
                    'priority' => $t->priority,
                    'customer_id' => $t->customer_id,
                    'customer_name' => $t->customer?->name,
                    'assigned_to_id' => $t->assigned_to,
                    'assigned_to_name' => $t->assignedTo?->name,
                    'sla_breach_at' => $breachAt?->toIso8601String(),
                    'is_breached' => $isBreached,
                    'is_breaching_soon' => $hoursUntilBreach !== null && $hoursUntilBreach <= 4,
                    'hours_until_breach' => $hoursUntilBreach,
                    'last_reply_at' => $t->updated_at?->toIso8601String(),
                    'message_count' => $t->messages_count,
                    'created_at' => $t->created_at?->toIso8601String(),
                    'time_ago' => $t->created_at?->diffForHumans(),
                ];
            });

        return Inertia::render('Internal/Support/Index', [
            'tickets' => $tickets,
            'summary' => $this->buildSummary(),
            'staff' => User::whereIn('role', ['super_admin', 'staff'])
                ->orderBy('name')
                ->get(['id', 'name']),
            'customers' => Customer::whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name', 'city']),
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'filters' => [
                'status' => $statusFilter,
                'priority' => $priorityFilter,
                'search' => $search,
            ],
        ]);
    }

    public function show(int $id): Response
    {
        $ticket = SupportTicket::with([
            'customer:id,name,city,trading_name',
            'messages' => fn ($q) => $q->orderBy('created_at')->with('sender:id,name,role'),
            'assignedTo:id,name',
        ])->findOrFail($id);

        // Active products this customer is on — surfaced in the
        // sidebar so support staff have the context without a tab
        // switch into the customer record.
        $customerProducts = $ticket->customer
            ? CustomerProduct::where('customer_id', $ticket->customer->id)
                ->whereIn('status', ['active', 'trial'])
                ->with('product:id,name,slug,icon_colour')
                ->get()
                ->map(fn (CustomerProduct $cp): array => [
                    'id' => $cp->id,
                    'product_name' => $cp->product?->name,
                    'product_slug' => $cp->product?->slug,
                    'icon_colour' => $cp->product?->icon_colour,
                    'status' => $cp->status,
                ])
                ->all()
            : [];

        return Inertia::render('Internal/Support/Show', [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'assigned_to_id' => $ticket->assigned_to,
                'assigned_to_name' => $ticket->assignedTo?->name,
                'sla_breach_at' => $ticket->sla_breach_at?->toIso8601String(),
                'resolved_at' => $ticket->resolved_at?->toIso8601String(),
                'closed_at' => $ticket->closed_at?->toIso8601String(),
                'created_at' => $ticket->created_at?->toIso8601String(),
                'updated_at' => $ticket->updated_at?->toIso8601String(),
                'time_ago' => $ticket->created_at?->diffForHumans(),
                'customer' => $ticket->customer ? [
                    'id' => $ticket->customer->id,
                    'name' => $ticket->customer->name,
                    'city' => $ticket->customer->city,
                    'trading_name' => $ticket->customer->trading_name,
                ] : null,
                'messages' => $ticket->messages->map(fn (SupportMessage $m): array => [
                    'id' => $m->id,
                    'sender_type' => $m->sender_type,
                    'sender_id' => $m->sender_id,
                    'sender_name' => $m->sender
                        ? $m->sender->name
                        : ($m->sender_type === 'customer'
                            ? ($ticket->customer ? $ticket->customer->name : 'Customer')
                            : ucfirst($m->sender_type)),
                    'sender_role' => $m->sender?->role,
                    'is_staff' => $m->sender_type === 'staff',
                    'is_internal_note' => $m->is_internal_note,
                    'body' => $m->body,
                    'created_at' => $m->created_at?->toIso8601String(),
                    'time_ago' => $m->created_at?->diffForHumans(),
                ])->values()->all(),
            ],
            'customer_products' => $customerProducts,
            'staff' => User::whereIn('role', ['super_admin', 'staff'])
                ->orderBy('name')
                ->get(['id', 'name']),
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'priority' => ['required', 'in:low,medium,high,urgent'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $ticket = DB::transaction(function () use ($data, $request) {
            $ticket = SupportTicket::create([
                'customer_id' => $data['customer_id'],
                'subject' => $data['subject'],
                'status' => 'open',
                'priority' => $data['priority'],
                'assigned_to' => $data['assigned_to'] ?? null,
                'sla_breach_at' => now()->addHours(self::SLA_HOURS[$data['priority']]),
            ]);

            // First message records who opened it — staff conversations
            // get an opening note even if they didn't have a customer
            // email to forward.
            SupportMessage::create([
                'ticket_id' => $ticket->id,
                'sender_type' => 'staff',
                'sender_id' => $request->user()?->id,
                'body' => $data['message'],
                'is_internal_note' => false,
            ]);

            $this->logActivity($request, 'support.ticket_created', $ticket->id, after: [
                'customer_id' => $ticket->customer_id,
                'subject' => $ticket->subject,
                'priority' => $ticket->priority,
            ]);

            return $ticket;
        });

        $this->forgetNavCaches();

        return redirect()
            ->route('internal.support.show', $ticket->id)
            ->with('success', "Ticket #{$ticket->id} created.");
    }

    public function reply(int $id, Request $request): RedirectResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'status' => ['nullable', 'in:open,in_progress,awaiting_customer,resolved,closed'],
        ]);

        DB::transaction(function () use ($ticket, $data, $request) {
            SupportMessage::create([
                'ticket_id' => $ticket->id,
                'sender_type' => 'staff',
                'sender_id' => $request->user()?->id,
                'body' => $data['message'],
                'is_internal_note' => false,
            ]);

            // Default after-status: "awaiting_customer" so the queue
            // reflects that the ball is in the customer's court.
            $newStatus = $data['status'] ?? 'awaiting_customer';
            $before = ['status' => $ticket->status];

            $update = ['status' => $newStatus];
            if ($newStatus === 'resolved' && ! $ticket->resolved_at) {
                $update['resolved_at'] = now();
            }
            if ($newStatus === 'closed' && ! $ticket->closed_at) {
                $update['closed_at'] = now();
            }

            $ticket->update($update);

            $this->logActivity($request, 'support.reply_sent', $ticket->id, $before, [
                'status' => $newStatus,
            ]);
        });

        $this->forgetNavCaches();

        return back()->with('success', 'Reply sent.');
    }

    public function updateStatus(int $id, Request $request): RedirectResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', 'in:open,in_progress,awaiting_customer,resolved,closed'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        DB::transaction(function () use ($ticket, $data, $request) {
            $before = ['status' => $ticket->status, 'assigned_to' => $ticket->assigned_to];

            $update = [
                'status' => $data['status'],
                'assigned_to' => $data['assigned_to'] ?? null,
            ];

            if ($data['status'] === 'resolved') {
                $update['resolved_at'] = $ticket->resolved_at ?? now();
            } else {
                $update['resolved_at'] = null;
            }
            if ($data['status'] === 'closed') {
                $update['closed_at'] = $ticket->closed_at ?? now();
            } else {
                $update['closed_at'] = null;
            }

            $ticket->update($update);

            $this->logActivity($request, 'support.status_updated', $ticket->id, $before, [
                'status' => $data['status'],
                'assigned_to' => $data['assigned_to'] ?? null,
            ]);
        });

        $this->forgetNavCaches();

        return back()->with('success', 'Ticket updated.');
    }

    /**
     * @return array<string, int>
     */
    private function buildSummary(): array
    {
        return [
            'open' => SupportTicket::where('status', 'open')->count(),
            'in_progress' => SupportTicket::where('status', 'in_progress')->count(),
            'awaiting_customer' => SupportTicket::where('status', 'awaiting_customer')->count(),
            'sla_breached' => SupportTicket::whereIn('status', ['open', 'in_progress'])
                ->where('sla_breach_at', '<', now())
                ->count(),
            'resolved_today' => SupportTicket::where('status', 'resolved')
                ->where('updated_at', '>=', now()->startOfDay())
                ->count(),
        ];
    }

    private function forgetNavCaches(): void
    {
        Cache::forget('nav.support_sla_breached');
        Cache::forget('nav.support_open');
    }

    private function logActivity(
        Request $request,
        string $action,
        int $ticketId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => 'support_ticket',
            'entity_id' => $ticketId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
