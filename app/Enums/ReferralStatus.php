<?php

namespace App\Enums;

/**
 * Deal-registration lifecycle on a lead — stored on
 * leads.referral_status. This is DELIBERATELY separate from the sales
 * pipeline `status` enum: a lead can be `new` in the pipeline while
 * `pending_review` as a registered deal.
 *
 * NULL (no case here) means "not a deal-registration" — the lead reached
 * us by some other path (manual entry, cookie-attributed web form). Those
 * leads are unaffected by the deal-registration loop.
 *
 *  - pending_review : referrer submitted it; awaiting staff review
 *  - approved       : staff approved; protection clock running (90 days)
 *  - rejected       : staff rejected (review_notes carries the reason)
 *  - expired        : protection window lapsed without conversion
 */
enum ReferralStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s): array => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
