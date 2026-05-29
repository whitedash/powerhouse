<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PortalUser;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer-facing support. The portal user can only see their own
 * customer's tickets — every query is scoped to customer_id and
 * findOrFail() is preferred over find() per the IDOR rule.
 *
 * SLA is staff-facing; we don't surface sla_breach_at to the
 * customer. Internal notes are filtered server-side so they never
 * leave the building.
 */
class SupportController extends Controller
{
    /**
     * Per-priority SLA budget. Mirrored from Internal\SupportController
     * because customer tickets are scored on the same scale — keeping
     * the table here avoids cross-controller coupling.
     *
     * @var array<string, int>
     */
    private const SLA_HOURS = [
        'urgent' => 4,
        'high' => 8,
        'medium' => 24,
        'low' => 72,
    ];

    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function index(): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $tickets = SupportTicket::where('customer_id', $portalUser->customer_id)
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (SupportTicket $t): array => [
                'id' => $t->id,
                'subject' => $t->subject,
                'status' => $t->status,
                'priority' => $t->priority,
                'messages_count' => $t->messages_count,
                'created_at' => $t->created_at?->diffForHumans(),
                'updated_at' => $t->updated_at?->diffForHumans(),
            ])
            ->all();

        return Inertia::render('Portal/Support/Index', [
            'tickets' => $tickets,
        ]);
    }

    public function show(int $id): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $ticket = SupportTicket::where('customer_id', $portalUser->customer_id)
            ->with([
                'messages' => fn ($q) => $q->where('is_internal_note', false)->orderBy('created_at'),
                'messages.sender:id,name',
                'product:id,name,icon_colour',
            ])
            ->findOrFail($id);

        return Inertia::render('Portal/Support/Show', [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'product' => $ticket->product?->name,
                'product_colour' => $ticket->product?->icon_colour,
                'created_at' => $ticket->created_at?->format('j M Y · H:i'),
                'updated_at' => $ticket->updated_at?->diffForHumans(),
                'messages' => $ticket->messages->map(fn (SupportMessage $m): array => [
                    'id' => $m->id,
                    'sender_type' => $m->sender_type,
                    'sender_name' => $this->resolveSenderName($m),
                    'body' => $m->body,
                    'created_at' => $m->created_at?->format('j M Y · H:i'),
                    'created_at_human' => $m->created_at?->diffForHumans(),
                ])->all(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:10000'],
            'priority' => ['required', 'string', 'in:'.implode(',', self::PRIORITIES)],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        $ticket = DB::transaction(function () use ($data, $portalUser): SupportTicket {
            $ticket = SupportTicket::create([
                'customer_id' => $portalUser->customer_id,
                'contact_id' => null,
                'product_id' => $data['product_id'] ?? null,
                'subject' => $data['subject'],
                'status' => 'open',
                'priority' => $data['priority'],
                'sla_breach_at' => now()->addHours(self::SLA_HOURS[$data['priority']] ?? 24),
            ]);

            SupportMessage::create([
                'ticket_id' => $ticket->id,
                'sender_type' => 'customer',
                'sender_id' => $portalUser->id,
                'body' => $data['message'],
                'is_internal_note' => false,
            ]);

            ActivityLog::create([
                'user_id' => $portalUser->id,
                'user_role' => 'portal',
                'action' => 'support.ticket_created_by_customer',
                'entity_type' => SupportTicket::class,
                'entity_id' => $ticket->id,
                'after' => ['subject' => $ticket->subject, 'priority' => $ticket->priority],
            ]);

            // A new ticket flips the staff sidebar badge — clear the
            // cache so staff see the new ticket immediately.
            Cache::forget('nav.support_open');

            return $ticket;
        });

        return redirect()->route('portal.support.show', $ticket->id)
            ->with('success', 'Ticket created — our team will reply shortly.');
    }

    public function reply(int $id, Request $request): RedirectResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $data = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $ticket = SupportTicket::where('customer_id', $portalUser->customer_id)
            ->findOrFail($id);

        DB::transaction(function () use ($ticket, $data, $portalUser) {
            SupportMessage::create([
                'ticket_id' => $ticket->id,
                'sender_type' => 'customer',
                'sender_id' => $portalUser->id,
                'body' => $data['message'],
                'is_internal_note' => false,
            ]);

            // Customer replying flips the ticket from 'awaiting_customer'
            // back to 'open' so it lands in staff's queue.
            if (in_array($ticket->status, ['awaiting_customer', 'resolved'], true)) {
                $ticket->status = 'open';
            }
            $ticket->touch();
            $ticket->save();

            ActivityLog::create([
                'user_id' => $portalUser->id,
                'user_role' => 'portal',
                'action' => 'support.reply_from_customer',
                'entity_type' => SupportTicket::class,
                'entity_id' => $ticket->id,
            ]);

            Cache::forget('nav.support_open');
        });

        return back()->with('success', 'Reply sent.');
    }

    /**
     * Map sender_type + sender relation into the customer-facing label.
     * Pulled out to dodge a "?->name on left of ??" Larastan warning
     * inside the deeply nested ->map closure.
     */
    private function resolveSenderName(SupportMessage $m): string
    {
        if ($m->sender_type === 'staff') {
            return $m->sender ? $m->sender->name : 'Whitedash team';
        }
        if ($m->sender_type === 'ai') {
            return 'Whitedash assistant';
        }

        return 'You';
    }
}
