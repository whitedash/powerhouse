<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Postmark inbound-email webhook. Turns a customer's email reply into a
 * support-ticket message (threading via the ticket+{id}@ Reply-To we set
 * on outbound replies, or the In-Reply-To header), and opens a fresh
 * ticket for first-contact emails.
 *
 * CSRF-exempt: covered by the `webhooks/*` exception in bootstrap/app.php.
 * Auth is the shared inbound secret, compared with hash_equals().
 */
class InboundEmailController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $expected = (string) config('services.postmark.inbound_secret');
        if ($expected !== '') {
            $provided = (string) ($request->header('X-Postmark-Signature') ?? $request->query('token', ''));
            if (! hash_equals($expected, $provided)) {
                abort(401);
            }
        }

        $data = $request->all();

        $fromEmail = (string) ($data['From'] ?? '');
        $subject = (string) ($data['Subject'] ?? '');
        $body = (string) ($data['TextBody'] ?? strip_tags((string) ($data['HtmlBody'] ?? '')));
        $toEmail = (string) ($data['To'] ?? '');
        $messageId = (string) ($data['MessageID'] ?? '');
        $headers = is_array($data['Headers'] ?? null) ? $data['Headers'] : [];

        DB::transaction(function () use ($fromEmail, $subject, $body, $toEmail, $messageId, $headers): void {
            $ticketId = $this->extractTicketId($toEmail, $headers);

            if ($ticketId !== null && ($ticket = SupportTicket::find($ticketId)) !== null) {
                SupportMessage::create([
                    'ticket_id' => $ticket->id,
                    'body' => Str::limit($body, 5000),
                    'sender_type' => 'customer',
                    'sender_id' => null,
                    'is_internal_note' => false,
                    'message_id' => $messageId ?: null,
                    'source' => 'email',
                ]);

                // A customer reply reopens the ticket.
                $ticket->update(['status' => 'open']);

                return;
            }

            // New ticket from a first-contact email. Match the sender to a
            // known contact so the ticket attaches to the right customer.
            $contact = Contact::where('email', $fromEmail)->first();

            $ticket = SupportTicket::create([
                'customer_id' => $contact?->customer_id,
                'contact_id' => $contact?->id,
                'subject' => Str::limit($subject, 255) ?: 'Email inquiry',
                'status' => 'open',
                'priority' => 'medium',
                'sla_breach_at' => now()->addHours(24),
            ]);

            // support_tickets has no body column — the opening message is a
            // SupportMessage, tagged as email-sourced.
            SupportMessage::create([
                'ticket_id' => $ticket->id,
                'body' => Str::limit($body, 5000) ?: '(no message body)',
                'sender_type' => 'customer',
                'sender_id' => null,
                'is_internal_note' => false,
                'message_id' => $messageId ?: null,
                'source' => 'email',
            ]);
        });

        return response()->json(['received' => true]);
    }

    /**
     * Resolve the ticket a reply belongs to: first from a
     * ticket+{id}@domain To-address, then by matching the In-Reply-To
     * header against a stored outbound message_id.
     *
     * @param  array<int, array<string, mixed>>  $headers
     */
    private function extractTicketId(string $toEmail, array $headers): ?int
    {
        if (preg_match('/ticket\+(\d+)@/', $toEmail, $m)) {
            return (int) $m[1];
        }

        foreach ($headers as $header) {
            if (($header['Name'] ?? '') === 'In-Reply-To') {
                $msg = SupportMessage::where('message_id', $header['Value'] ?? '')->first();
                if ($msg !== null) {
                    return $msg->ticket_id;
                }
            }
        }

        return null;
    }
}
