<?php

namespace App\Enums;

/**
 * Derived first-response SLA state for a support ticket — COMPUTED ON READ
 * from first_responded_at vs sla_breach_at vs now(). Never stored, never
 * swept (see SupportTicket::slaState()).
 *
 *  - met      : responded on/before the deadline
 *  - due      : not yet responded, still within the deadline (countdown)
 *  - breached : responded late, OR not responded and deadline passed
 */
enum SlaState: string
{
    case Met = 'met';
    case Due = 'due';
    case Breached = 'breached';

    public function label(): string
    {
        return match ($this) {
            self::Met => 'Met',
            self::Due => 'Due',
            self::Breached => 'Breached',
        };
    }
}
