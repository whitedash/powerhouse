<?php

namespace App\Support;

use App\Models\BillingEntity;

/**
 * Single source of truth for how much VAT an invoice carries.
 *
 * The applicable RATE comes solely from BillingEntity::effective_vat_rate,
 * which is 0 whenever the entity is not VAT-registered. So a non-registered
 * entity can never be charged VAT — a caller cannot opt back in, and every
 * generator + the manual create/update path produce the same canonical
 * [vat_rate, vat_amount, total] from one place. This is what makes "VAT on a
 * non-registered entity" impossible by construction rather than by a guard
 * each caller has to remember.
 */
class InvoiceVat
{
    /**
     * Resolve the VAT breakdown for a net (pre-VAT) amount issued by an entity.
     *
     * A non-registered entity (effective rate 0) forces zero VAT regardless of
     * any staff-requested rate. A registered entity honours an explicit
     * $requestedRate when given (e.g. a 5% reduced rate chosen on a manual
     * invoice); otherwise its effective default rate applies.
     *
     * @return array{vat_rate: float, vat_amount: float, total: float}
     */
    public static function breakdown(float $net, ?BillingEntity $entity, ?float $requestedRate = null): array
    {
        $entityRate = $entity !== null ? (float) $entity->effective_vat_rate : 0.0;

        $rate = $entityRate <= 0.0
            ? 0.0
            : ($requestedRate ?? $entityRate);

        $vatAmount = round($net * ($rate / 100), 2);

        return [
            'vat_rate' => $rate,
            'vat_amount' => $vatAmount,
            'total' => round($net + $vatAmount, 2),
        ];
    }
}
