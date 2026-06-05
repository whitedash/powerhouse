<?php

namespace App\Services;

use App\Models\CommissionRule;
use Illuminate\Support\Carbon;

/**
 * Resolves the best-matching active commission rule for a
 * referrer × product over a period. Extracted verbatim from
 * MaavelusStatementService::resolveRule so the Maavelus statement flow and
 * the invoice-paid accrual engine share ONE source of truth.
 *
 * Precedence: a referrer-specific rule wins over the product-wide default
 * (referrer_id IS NULL); the rule must be active and effective-dated to
 * cover the period.
 */
class CommissionRuleResolver
{
    public function resolve(int $referrerId, int $productId, Carbon $periodStart, Carbon $periodEnd): ?CommissionRule
    {
        return CommissionRule::where('product_id', $productId)
            ->where('is_active', true)
            ->where(function ($q) use ($referrerId) {
                $q->where('referrer_id', $referrerId)
                    ->orWhereNull('referrer_id');
            })
            ->where('valid_from', '<=', $periodStart)
            ->where(function ($q) use ($periodEnd) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $periodEnd);
            })
            ->orderByDesc('referrer_id')
            ->first();
    }
}
