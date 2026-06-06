<?php

namespace App\Services;

use App\Mail\SupportTicketReceived;
use App\Mail\SupportTicketStaffAlert;
use App\Models\ActivityLog;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The single intake path for a new support ticket — used by the public
 * /support form AND the create_ticket workflow action. Creates the ticket,
 * its first (customer) message, and an admin triage Task atomically, then
 * sends the acknowledgement email after commit.
 *
 * Guests have no customer record: customer_id is null and the submitter's
 * details live in the guest_* columns.
 */
class TicketIntakeService
{
    /**
     * @param  array{
     *     subject: string,
     *     message: string,
     *     priority?: string,
     *     customer_id?: int|null,
     *     guest_name?: string|null,
     *     guest_email?: string|null,
     *     guest_phone?: string|null,
     *     product_id?: int|null,
     * }  $data
     */
    public function create(array $data, string $source = 'web'): SupportTicket
    {
        $priority = $data['priority'] ?? 'medium';

        $ticket = DB::transaction(function () use ($data, $priority, $source): SupportTicket {
            $ticket = SupportTicket::create([
                'customer_id' => $data['customer_id'] ?? null,
                'guest_name' => $data['guest_name'] ?? null,
                'guest_email' => $data['guest_email'] ?? null,
                'guest_phone' => $data['guest_phone'] ?? null,
                'product_id' => $data['product_id'] ?? null,
                'subject' => $data['subject'],
                'status' => 'open',
                'priority' => $priority,
                'sla_breach_at' => now()->addHours(SupportTicket::firstResponseHours($priority)),
            ]);

            SupportMessage::create([
                'ticket_id' => $ticket->id,
                'sender_type' => 'customer',
                'sender_id' => null, // guest / unauthenticated submitter
                'body' => $data['message'],
                'is_internal_note' => false,
                'source' => $source,
            ]);

            // Admin triage Task, now first-class linked via ticket_id (the
            // title still names the ticket for legacy/readability). Both
            // created_by AND assigned_to are the resolved triage user:
            // tasks.assigned_to is NOT NULL (FK, restrictOnDelete), so a
            // truly "unassigned" task isn't possible without a schema change
            // — the ticket lands in the triage user's queue instead.
            // Resolving to a guaranteed-real user also means this insert can
            // never FK-fail and roll back a guest's ticket.
            $triageUserId = $this->triageUserId();
            Task::create([
                'customer_id' => $ticket->customer_id, // null for guests
                'ticket_id' => $ticket->id,
                'title' => "New support ticket #{$ticket->id} — ".Str::limit($ticket->subject, 80),
                'description' => Str::limit($data['message'], 500),
                'type' => 'task',
                'priority' => $priority === 'urgent' ? 'high' : ($priority === 'low' ? 'low' : 'medium'),
                'status' => 'todo',
                // due_at is mandatory on tasks; the triage task is due by the
                // ticket's first-response SLA deadline (never "now"/overdue).
                'due_at' => now()->addHours(SupportTicket::firstResponseHours($priority)),
                'assigned_to' => $triageUserId,
                'created_by' => $triageUserId,
            ]);

            ActivityLog::create([
                'user_id' => null,
                'user_role' => 'guest',
                'action' => 'support.ticket_created',
                'entity_type' => SupportTicket::class,
                'entity_id' => $ticket->id,
                'after' => [
                    'subject' => $ticket->subject,
                    'priority' => $ticket->priority,
                    'source' => $source,
                    'guest' => $ticket->customer_id === null,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
            ]);

            return $ticket;
        });

        // Send the acknowledgement AFTER commit (a mail failure must not roll
        // back a real ticket) and synchronously (no dependency on a worker).
        $recipient = $ticket->guest_email
            ?? $ticket->loadMissing('customer.primaryContact')->customer?->primaryContact?->email;

        // A failed acknowledgement must NEVER 500 a guest submission — the
        // ticket is already committed. Report and continue.
        if ($recipient) {
            try {
                Mail::to($recipient)->send(new SupportTicketReceived($ticket));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Alert staff by email (not just the in-app triage Task). Goes to
        // support.notify_email, falling back to the resolved triage user's
        // address. Same guard: a failed alert must never 500/roll back.
        $staffEmail = config('support.notify_email')
            ?: User::whereKey($this->triageUserId())->value('email');
        if ($staffEmail) {
            try {
                Mail::to($staffEmail)->send(new SupportTicketStaffAlert($ticket));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $ticket;
    }

    /**
     * The configured triage user if it exists, else the first super_admin.
     * Guarantees a real users.id for the Task's NOT-NULL created_by FK so a
     * missing seeded user can never drop a guest's ticket.
     */
    private function triageUserId(): int
    {
        $configured = config('support.triage_user_id');
        if ($configured !== null && User::whereKey($configured)->exists()) {
            return (int) $configured;
        }

        return (int) User::where('role', 'super_admin')->orderBy('id')->value('id');
    }
}
