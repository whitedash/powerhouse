<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;

/**
 * Centralises the two SLA stamps so every code path (staff reply, status
 * change, inbound-email reopen) stays consistent. Each stamp is atomic
 * (txn) and audited (activity_log). Breach itself is computed on read
 * (SupportTicket::slaState) — nothing here stores a breach flag.
 */
class SupportSlaService
{
    /**
     * Stamp first_responded_at on the FIRST staff, non-internal reply.
     * Idempotent: a second reply (or an internal note that never calls this)
     * leaves the original timestamp untouched.
     */
    public function stampFirstResponse(SupportTicket $ticket, ?int $userId): void
    {
        if ($ticket->first_responded_at !== null) {
            return;
        }

        DB::transaction(function () use ($ticket, $userId): void {
            $ticket->forceFill(['first_responded_at' => now()])->save();

            $this->log('support.first_response', $ticket, $userId, [
                'first_responded_at' => $ticket->first_responded_at?->toIso8601String(),
                'within_sla' => $ticket->slaState()?->value,
            ]);
        });
    }

    /**
     * Record a reopen when a ticket moves resolved|closed → open. Pass the
     * PRE-transition status (the caller has already set status to 'open').
     * No-op for any other transition, so it's safe to call on every
     * "bring back to open" path. Stamps reopened_at + increments reopen_count.
     */
    public function recordReopen(SupportTicket $ticket, string $previousStatus, ?int $userId, string $via): void
    {
        if (! in_array($previousStatus, ['resolved', 'closed'], true) || $ticket->status !== 'open') {
            return;
        }

        DB::transaction(function () use ($ticket, $userId, $via, $previousStatus): void {
            $ticket->forceFill([
                'reopened_at' => now(),
                'reopen_count' => $ticket->reopen_count + 1,
            ])->save();

            $this->log('support.reopened', $ticket, $userId, [
                'from' => $previousStatus,
                'via' => $via,
                'reopen_count' => $ticket->reopen_count,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function log(string $action, SupportTicket $ticket, ?int $userId, array $after): void
    {
        ActivityLog::create([
            'user_id' => $userId,
            'user_role' => $userId === null ? 'system' : null,
            'action' => $action,
            'entity_type' => 'support_ticket',
            'entity_id' => $ticket->id,
            'before' => null,
            'after' => $after,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);
    }
}
