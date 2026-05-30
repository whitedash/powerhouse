<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Auto-closes support tickets that have been waiting on a customer
 * response for longer than the configured threshold. The threshold
 * lives in the settings table under `support.auto_close_days` so
 * staff can tune it from the Notifications settings page without a
 * deploy.
 *
 * Why awaiting_customer only: those are tickets where the ball is
 * already in the customer's court. Open or in_progress tickets mean
 * staff still owe a reply and shouldn't be silently closed.
 */
class CloseInactiveTickets extends Command
{
    /** @var string */
    protected $signature = 'support:close-inactive '
        .'{--dry-run : List the tickets that would be closed without changing anything}';

    /** @var string */
    protected $description = 'Close awaiting_customer tickets that have been silent for the configured window.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = $this->resolveAutoCloseDays();

        if ($days < 1) {
            $this->info('Auto-close disabled (support.auto_close_days < 1). Nothing to do.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        $tickets = SupportTicket::where('status', 'awaiting_customer')
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($tickets->isEmpty()) {
            $this->info(sprintf('No tickets idle for more than %d days.', $days));

            return self::SUCCESS;
        }

        $closed = 0;
        foreach ($tickets as $ticket) {
            try {
                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] Would close #%d (last update %s)',
                        $ticket->id,
                        $ticket->updated_at?->diffForHumans() ?? 'unknown',
                    ));
                    $closed++;

                    continue;
                }

                DB::transaction(function () use ($ticket, $days) {
                    $ticket->update([
                        'status' => 'closed',
                        'closed_at' => now(),
                    ]);

                    SupportMessage::create([
                        'ticket_id' => $ticket->id,
                        // Null sender + 'ai' sender_type marks this
                        // as a system-generated message in the
                        // conversation history.
                        'sender_id' => null,
                        'sender_type' => 'ai',
                        'body' => sprintf(
                            'Ticket automatically closed after %d days without a response.',
                            $days,
                        ),
                        'is_internal_note' => false,
                    ]);

                    ActivityLog::create([
                        'user_id' => null,
                        'user_role' => 'system',
                        'action' => 'support.auto_closed',
                        'entity_type' => 'support_ticket',
                        'entity_id' => $ticket->id,
                        'before' => ['status' => 'awaiting_customer'],
                        'after' => [
                            'status' => 'closed',
                            'reason' => 'inactive_'.$days.'_days',
                        ],
                        'ip_address' => null,
                        'user_agent' => 'artisan:support:close-inactive',
                    ]);

                    Cache::forget('nav.support_open');
                });

                $this->info('Closed: #'.$ticket->id);
                $closed++;
            } catch (Throwable $e) {
                $this->error(sprintf('Failed to close #%d: %s', $ticket->id, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            '%d ticket%s closed.%s',
            $closed,
            $closed === 1 ? '' : 's',
            $dryRun ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Pull the configured inactivity window. Stored as a JSON-encoded
     * integer the same way the Settings\NotificationsController
     * encodes its values — the controller calls this through
     * `decodeSettingValue` but for a single integer we can parse it
     * inline without dragging that helper in.
     */
    private function resolveAutoCloseDays(): int
    {
        $raw = Setting::query()->where('key', 'support.auto_close_days')->value('value');
        if ($raw === null) {
            return 7;
        }

        // The settings table stores values JSON-encoded — a plain
        // integer round-trips as the same string, so json_decode
        // and (int) both work.
        $decoded = json_decode((string) $raw, true);
        if (is_int($decoded)) {
            return max(0, $decoded);
        }

        return max(0, (int) $raw);
    }
}
