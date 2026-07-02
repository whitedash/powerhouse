<?php

namespace App\Services;

use App\Enums\ReferralStatus;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Referrer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deal registration — the register → review → protect loop.
 *
 * A referrer registers a prospective deal; staff review it; on approval a
 * fixed 90-day protection window opens (clock starts at APPROVAL). The
 * registered deal is just a Lead with referral_status set, so it flows
 * through the existing pipeline + AttributionService spine on conversion.
 *
 * Net-new is enforced at registration: an incoming prospect that already
 * exists (as a customer/contact, or a lead owned by someone else) is
 * rejected with a NON-REVEALING reason — we never leak the existing record.
 */
class DealRegistrationService
{
    /**
     * Register a deal for the AUTHENTICATED referrer. referrer_id is always
     * taken from $referrer, never from caller-supplied data.
     *
     * @param  array{company?: string|null, contact_name?: string|null, email?: string|null, phone?: string|null, notes?: string|null, product_id?: int|null}  $data
     *
     * @throws ValidationException when the prospect is not net-new.
     */
    public function register(Referrer $referrer, array $data): Lead
    {
        $email = isset($data['email']) ? trim((string) $data['email']) : null;
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : null;
        $company = isset($data['company']) ? trim((string) $data['company']) : null;

        $this->assertNetNew($referrer, $email ?: null, $phone ?: null, $company ?: null);

        [$firstName, $lastName] = $this->splitName($data['contact_name'] ?? null);

        // The chosen product is recorded in source_detail (Sprint-1 schema
        // adds no product column to leads) so the deals list can show it and
        // convert() carries it onto customer.channel_detail.
        $productName = isset($data['product_id'])
            ? Product::where('id', $data['product_id'])->value('name')
            : null;

        return DB::transaction(function () use ($referrer, $data, $email, $phone, $company, $firstName, $lastName, $productName): Lead {
            $lead = Lead::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'company' => $company ?: null,
                'source' => 'referral',
                'source_detail' => $productName,
                'status' => 'new',
                // referrer_id is the AUTHENTICATED referrer — never input.
                'referrer_id' => $referrer->id,
                'referral_code' => $referrer->referral_code,
                'referral_status' => ReferralStatus::PendingReview,
                'registered_at' => now(),
                'referral_consent_at' => now(),
                'notes' => $data['notes'] ?? null,
                // leads.created_by is NOT NULL → the referrer's own user.
                'created_by' => $referrer->user_id,
            ]);

            $this->log('referral.deal_registered', $lead, $referrer->user_id, 'referrer', [
                'referrer_id' => $referrer->id,
                'product_id' => $data['product_id'] ?? null,
                'company' => $lead->company,
            ]);

            return $lead;
        });
    }

    /**
     * Approve a registered deal. Opens the fixed protection window — the
     * clock starts NOW (approval time), not at registration.
     */
    public function approve(Lead $lead, User $reviewer): Lead
    {
        return DB::transaction(function () use ($lead, $reviewer): Lead {
            $before = ['referral_status' => $lead->referral_status?->value];

            $lead->update([
                'referral_status' => ReferralStatus::Approved,
                'protected_until' => now()->addDays((int) config('referrals.protection_days', 90)),
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $this->log('referral.deal_approved', $lead, $reviewer->id, $reviewer->role, [
                ...$before,
                'protected_until' => $lead->protected_until?->toIso8601String(),
            ]);

            return $lead;
        });
    }

    /**
     * Reject a registered deal. The reason is stored in review_notes.
     */
    public function reject(Lead $lead, User $reviewer, string $reason): Lead
    {
        return DB::transaction(function () use ($lead, $reviewer, $reason): Lead {
            $before = ['referral_status' => $lead->referral_status?->value];

            $lead->update([
                'referral_status' => ReferralStatus::Rejected,
                'review_notes' => $reason,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $this->log('referral.deal_rejected', $lead, $reviewer->id, $reviewer->role, [
                ...$before,
                'reason' => $reason,
            ]);

            return $lead;
        });
    }

    /**
     * Net-new guard. Matches on email → else phone → else company, against
     * existing customers/contacts AND leads. A match that is NOT this
     * referrer's own prior registration blocks the registration. The thrown
     * message is deliberately non-revealing (never names the existing row).
     *
     * @throws ValidationException
     */
    private function assertNetNew(Referrer $referrer, ?string $email, ?string $phone, ?string $company): void
    {
        $blocked = false;

        if ($email !== null) {
            $blocked = $this->contactMatch(fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]))
                || $this->leadBlocks($referrer, fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]));
        } elseif ($phone !== null) {
            $blocked = $this->contactMatch(fn ($q) => $q->where('phone', $phone))
                || $this->leadBlocks($referrer, fn ($q) => $q->where('phone', $phone));
        } elseif ($company !== null) {
            $blocked = Company::whereRaw('LOWER(name) = ?', [mb_strtolower($company)])->exists()
                || $this->leadBlocks($referrer, fn ($q) => $q->whereRaw('LOWER(company) = ?', [mb_strtolower($company)]));
        }

        if ($blocked) {
            // Non-revealing: we never disclose which record matched.
            throw ValidationException::withMessages([
                'email' => "We couldn't register this deal — this prospect may already be in our system or registered by another partner. Please contact your account manager to check.",
            ]);
        }
    }

    /**
     * True when a customer Contact matches the predicate.
     *
     * @param  callable(Builder): Builder  $where
     */
    private function contactMatch(callable $where): bool
    {
        return Contact::query()->where($where)->exists();
    }

    /**
     * True when a matching lead exists that is NOT this referrer's own
     * prior registration (a house lead or another referrer's deal blocks;
     * the referrer re-touching their own prospect does not).
     *
     * @param  callable(Builder): Builder  $where
     */
    private function leadBlocks(Referrer $referrer, callable $where): bool
    {
        return Lead::query()
            ->where($where)
            ->where(fn ($q) => $q->whereNull('referrer_id')->orWhere('referrer_id', '!=', $referrer->id))
            ->exists();
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['Referred contact', null];
        }
        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = array_shift($parts);

        return [$first, $parts !== [] ? implode(' ', $parts) : null];
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function log(string $action, Lead $lead, int $userId, ?string $role, array $after): void
    {
        ActivityLog::create([
            'user_id' => $userId,
            'user_role' => $role,
            'action' => $action,
            'entity_type' => Lead::class,
            'entity_id' => $lead->id,
            'before' => null,
            'after' => $after,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);
    }
}
