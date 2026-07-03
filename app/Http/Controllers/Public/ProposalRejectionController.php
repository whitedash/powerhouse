<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\ProposalRejectedCustomer;
use App\Mail\ProposalRejectedStaff;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Services\ContactMatchService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public proposal rejection flow — NO auth middleware. Mirror of
 * ProposalAcceptanceController: the customer reaches it via the SAME
 * token minted at send-time (the acceptance link doubles as the reject
 * link — one token, the URL path chooses accept vs decline). The token
 * is the only authorisation; once rejected we null it out so it is
 * single-use, exactly as accept does — a proposal can be accepted OR
 * rejected, never both.
 */
class ProposalRejectionController extends Controller
{
    public function show(string $token): Response
    {
        $proposal = $this->loadByToken($token);

        // Already-rejected proposals show the confirmation page rather than a
        // 404 (mirrors accept's already-accepted branch). In practice the token
        // is nulled on rejection so a re-visit 404s in loadByToken first; this
        // covers the race where status flipped but the token still matched.
        if ($proposal->status === 'rejected') {
            return Inertia::render('Public/ProposalRejected', [
                'reference' => $proposal->reference,
                'customer_name' => $proposal->customer->name,
                'already' => true,
            ]);
        }

        // Expired tokens 410 — the customer needs a fresh link from sales.
        if ($proposal->acceptance_token_expires_at !== null
            && $proposal->acceptance_token_expires_at->isPast()) {
            abort(410, 'This proposal has expired. Please contact us for a fresh link.');
        }

        if ($proposal->status !== 'sent') {
            abort(410, 'This proposal is no longer available.');
        }

        return Inertia::render('Public/ProposalReject', [
            'proposal' => [
                'reference' => $proposal->reference,
                'title' => $proposal->title,
                'total' => (float) $proposal->total,
                'customer_name' => $proposal->customer->name,
            ],
            'token' => $token,
        ]);
    }

    public function reject(string $token, Request $request): Response
    {
        $proposal = $this->loadByToken($token);

        // Re-validate every condition — a stale tab can lag enough that show()
        // said "ok" but reject() now says "no".
        abort_unless(
            $proposal->status === 'sent'
            && ($proposal->acceptance_token_expires_at === null
                || $proposal->acceptance_token_expires_at->isFuture()),
            410,
            'This link is no longer valid.'
        );

        $data = $request->validate([
            // Reject's one extra input over accept: an optional free-text reason.
            'rejection_reason' => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($proposal, $data, $request) {
            $proposal->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejection_reason' => $data['rejection_reason'] ?? null,
                // Invalidate the token — single-use, same as accept.
                'acceptance_token' => null,
            ]);

            // Audit row — actor is the public visitor, so user_id stays null and
            // user_role is 'guest' (mirrors the proposal.accepted entry).
            ActivityLog::create([
                'user_id' => null,
                'user_role' => 'guest',
                'action' => 'proposal.rejected',
                'entity_type' => 'proposal',
                'entity_id' => $proposal->id,
                'after' => [
                    'reference' => $proposal->reference,
                    'reason' => $data['rejection_reason'] ?? null,
                    'ip' => $request->ip(),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);

            // Best-effort: attribute the rejection back to a pipeline lead
            // (proposal -> lost). Wrapped so a matching hiccup can't undo the
            // customer's binding rejection.
            try {
                $this->maybeMarkMatchedLeadLost($proposal);
            } catch (\Throwable $e) {
                Log::error('Proposal lead-match transition failed', [
                    'proposal_id' => $proposal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // In-app notification to the proposal's author.
        app(NotificationService::class)->notifyProposalRejected($proposal);

        // Email both sides outside the transaction — swallow failures so a
        // Postmark blip never turns the committed rejection into an error page.
        try {
            $creator = User::find($proposal->created_by);
            if ($creator?->email) {
                Mail::to($creator->email)->send(new ProposalRejectedStaff($proposal));
            }

            $proposal->loadMissing('customer.primaryContact');
            $customerEmail = $proposal->customer->primaryContact?->email;
            if ($customerEmail) {
                Mail::to($customerEmail)->send(new ProposalRejectedCustomer($proposal));
            }
        } catch (\Throwable $e) {
            Log::error('Proposal rejection email failed', [
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
            ]);
        }

        return Inertia::render('Public/ProposalRejected', [
            'reference' => $proposal->reference,
            'customer_name' => $proposal->customer->name,
            'already' => false,
        ]);
    }

    /**
     * Look up a proposal by token; 404 on miss. Identical semantics to
     * ProposalAcceptanceController::loadByToken — the reject link reuses the
     * acceptance_token, hashed at rest, constant-time re-verified.
     */
    private function loadByToken(string $token): Proposal
    {
        $tokenHash = hash('sha256', $token);

        $proposal = Proposal::where('acceptance_token', $tokenHash)
            ->with(['customer.primaryContact', 'billingEntity'])
            ->first();

        // Constant-time re-verification. A nulled token (already accepted or
        // rejected) never matches, so a dead bookmark 404s.
        if ($proposal === null
            || $proposal->acceptance_token === null
            || ! hash_equals($proposal->acceptance_token, $tokenHash)) {
            abort(404, 'Proposal not found.');
        }

        return $proposal;
    }

    /**
     * Attribute a rejected proposal back to a pipeline lead: proposal -> lost.
     *
     * Clone of ProposalAcceptanceController::maybeMarkMatchedLeadWon with the
     * same guards — the proposal's customer's primary-contact email is matched
     * against unconverted leads:
     *  - only an UNCONVERTED (customer_id null) lead is a candidate;
     *  - only a UNIQUE match transitions — leads.email is NOT unique, so more
     *    than one hit is flagged (a proposal.lead_match_ambiguous activity_log
     *    row, the same mechanism accept uses) and NOTHING is auto-picked;
     *  - only a lead in exactly 'proposal' status transitions; any other
     *    status is left untouched.
     */
    private function maybeMarkMatchedLeadLost(Proposal $proposal): void
    {
        $proposal->loadMissing('customer.primaryContact');

        $email = app(ContactMatchService::class)->normalizeEmail(
            $proposal->customer->primaryContact?->email
        );
        if ($email === null) {
            return; // no email to infer from — silent no-op
        }

        $candidates = Lead::whereNull('customer_id')->where('email', $email)->get();

        if ($candidates->isEmpty()) {
            return; // no pipeline lead to attribute — silent no-op
        }

        if ($candidates->count() > 1) {
            // Ambiguous — never auto-pick. Flag for staff to resolve (same
            // action key accept uses, so both sides surface identically).
            ActivityLog::create([
                'user_id' => null,
                'user_role' => 'system',
                'action' => 'proposal.lead_match_ambiguous',
                'entity_type' => 'proposal',
                'entity_id' => $proposal->id,
                'after' => [
                    'email' => $email,
                    'candidate_lead_ids' => $candidates->pluck('id')->all(),
                    'count' => $candidates->count(),
                ],
            ]);

            return;
        }

        $lead = $candidates->first();
        if ($lead->status !== 'proposal') {
            return; // only proposal -> lost; leave any other status alone
        }

        $lead->update(['status' => 'lost']);

        ActivityLog::create([
            'user_id' => null,
            'user_role' => 'system',
            'action' => 'lead.status_changed',
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'before' => null,
            'after' => [
                'from' => 'proposal',
                'to' => 'lost',
                'via' => 'proposal_rejected',
                'proposal_id' => $proposal->id,
            ],
        ]);
    }
}
