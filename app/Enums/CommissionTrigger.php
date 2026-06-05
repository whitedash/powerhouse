<?php

namespace App\Enums;

/**
 * What revenue event accrued a commission_ledger row — stored on
 * commission_ledger.trigger_type (the DB column stays a plain enum string;
 * we do NOT cast it on the model, so existing reads are unaffected).
 *
 *  - onboarding        : first/one-off accrual for a customer×product
 *  - invoice_paid      : a recurring (subscription-invoice) accrual
 *  - monthly_recurring : the Maavelus statement flow (its own path)
 */
enum CommissionTrigger: string
{
    case Onboarding = 'onboarding';
    case InvoicePaid = 'invoice_paid';
    case MonthlyRecurring = 'monthly_recurring';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $t): string => $t->value, self::cases());
    }
}
