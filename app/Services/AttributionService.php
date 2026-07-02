<?php

namespace App\Services;

use App\Enums\AttributionSource;
use App\Enums\ReferralStatus;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CustomerReferral;
use App\Models\Lead;
use App\Models\ReferralClick;
use App\Models\Referrer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The attribution spine. Resolves a referral code (from ?ref or the
 * wd_ref cookie) to a referrer, and — at the point a customer is created
 * — writes the single immutable customer_referrals row.
 *
 * Rules (locked): last-touch within a 60-day window, new-customers-only,
 * self-referral blocked, one attribution per customer (unique customer_id).
 * Commission generation is deliberately NOT triggered here (later phase).
 */
class AttributionService
{
    /**
     * The referral code on the current request: ?ref wins over the
     * wd_ref cookie. Returns a normalised code or null.
     */
    public function resolveCode(Request $request): ?string
    {
        $raw = $request->query('ref')
            ?? $request->cookie((string) config('referrals.cookie_name', 'wd_ref'));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return ReferralCodeGenerator::normalise($raw);
    }

    /**
     * Active referrer for a code, or null. Inactive referrers never
     * attribute.
     */
    public function referrerForCode(?string $code): ?Referrer
    {
        if ($code === null || $code === '') {
            return null;
        }

        return Referrer::where('referral_code', $code)
            ->where('is_active', true)
            ->first();
    }

    public function referrerFromRequest(Request $request): ?Referrer
    {
        return $this->referrerForCode($this->resolveCode($request));
    }

    /**
     * Newest click for a code within the attribution window — the
     * "last-touch" event. Null if the visitor never went through /r/{code}
     * (e.g. a code typed straight into a form); attribution still proceeds
     * with a null click_id.
     */
    public function lastTouchClick(string $code): ?ReferralClick
    {
        $cutoff = now()->subDays((int) config('referrals.window_days', 60));

        return ReferralClick::where('referral_code', $code)
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Attribute a freshly-created customer from a lead that carried a
     * referrer (captured at public-form submission). No-op (returns null)
     * when the lead has no referrer.
     */
    public function attributeFromLead(Company $customer, Lead $lead): ?CustomerReferral
    {
        // Deal-registration guard: a rejected or expired registration must
        // never attribute. approved (and null — non-deal leads) attribute
        // as before; pending_review is a transient state pre-conversion.
        if (in_array($lead->referral_status, [ReferralStatus::Rejected, ReferralStatus::Expired], true)) {
            return null;
        }

        $referrer = $lead->referrer_id !== null
            ? Referrer::where('id', $lead->referrer_id)->where('is_active', true)->first()
            : $this->referrerForCode($lead->referral_code);

        if ($referrer === null) {
            return null;
        }

        $click = $lead->referral_code !== null ? $this->lastTouchClick($lead->referral_code) : null;

        return $this->attribute(
            customer: $customer,
            referrer: $referrer,
            source: AttributionSource::Param,
            lead: $lead,
            click: $click,
            subjectEmail: $lead->email,
        );
    }

    /**
     * Write the immutable attribution row. Enforces new-customers-only
     * (skips if one already exists) and the self-referral guard. Returns
     * null when a guard blocks it. Wrapped in a transaction + logged.
     */
    public function attribute(
        Company $customer,
        Referrer $referrer,
        AttributionSource $source,
        ?Lead $lead = null,
        ?ReferralClick $click = null,
        ?string $subjectEmail = null,
    ): ?CustomerReferral {
        // New-customers-only: one immutable attribution per customer.
        if (CustomerReferral::where('customer_id', $customer->id)->exists()) {
            return null;
        }

        // Self-referral guard: a referrer can't refer themselves.
        if ($this->isSelfReferral($referrer, $customer, $subjectEmail)) {
            return null;
        }

        return DB::transaction(function () use ($customer, $referrer, $source, $lead, $click): CustomerReferral {
            $referral = CustomerReferral::create([
                'customer_id' => $customer->id,
                'referrer_id' => $referrer->id,
                'lead_id' => $lead?->id,
                'click_id' => $click?->id,
                'product' => $click?->product,
                'source' => $source,
                'campaign' => $click?->campaign,
                'attributed_at' => now(),
                'converted_at' => now(),
            ]);

            ActivityLog::create([
                'user_id' => null,
                'user_role' => null,
                'action' => 'referral.attributed',
                'entity_type' => CustomerReferral::class,
                'entity_id' => $referral->id,
                'before' => null,
                'after' => [
                    'customer_id' => $customer->id,
                    'referrer_id' => $referrer->id,
                    'source' => $source->value,
                    'click_id' => $click?->id,
                    'lead_id' => $lead?->id,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
            ]);

            return $referral;
        });
    }

    /**
     * True when the referrer's own user email matches the customer/lead
     * email — i.e. someone using their own link to sign up.
     */
    private function isSelfReferral(Referrer $referrer, Company $customer, ?string $subjectEmail): bool
    {
        $referrerEmail = strtolower((string) $referrer->user?->email);
        if ($referrerEmail === '') {
            return false;
        }

        $candidates = array_filter([
            $subjectEmail,
            $customer->primaryContact?->email,
        ]);

        foreach ($candidates as $email) {
            if (strtolower((string) $email) === $referrerEmail) {
                return true;
            }
        }

        return false;
    }
}
