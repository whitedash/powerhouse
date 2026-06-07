<?php

namespace App\Support;

use App\Models\CustomerProduct;
use App\Models\Domain;

/**
 * Stage 1a cleanup: a domain must never be modelled as a CustomerProduct.
 * Converts every is_domain CustomerProduct into a self-billing Domain and
 * then deletes the CP.
 *
 * The cardinal rule: NEVER delete a domain CP without a Domain to carry the
 * asset. For each CP we first ensure a Domain exists for that customer+TLD
 * (creating one from the CP if missing), back-fill the tld / plan / auto_renew
 * it needs to self-bill, and only then delete the CP.
 *
 * Idempotent: keyed on surviving is_domain CPs, so a re-run finds none and is
 * a no-op. On prod this converts the single mislabeled id=1 row; on a DB with
 * no domain CPs it does nothing.
 */
class DomainCustomerProductConverter
{
    /**
     * @return array{converted:int,domains_created:int,domains_updated:int}
     */
    public static function run(): array
    {
        $converted = 0;
        $created = 0;
        $updated = 0;

        $domainCps = CustomerProduct::whereHas('productPlan', fn ($q) => $q->where('is_domain', true))
            ->with('productPlan')
            ->get();

        foreach ($domainCps as $cp) {
            $plan = $cp->productPlan;
            $tld = $plan?->tld;

            $domain = self::findExistingDomain($cp->customer_id, $tld);

            if ($domain === null) {
                // No asset yet — create one from the CP so deleting the CP
                // never loses the domain. self-billing fields set up front.
                Domain::create([
                    'customer_id' => $cp->customer_id,
                    'domain' => self::deriveDomainName($cp, $tld),
                    'tld' => $tld,
                    'product_plan_id' => $plan?->id,
                    'auto_renew' => true,
                    'status' => 'active',
                    'ssl_status' => 'none',
                ]);
                $created++;
            } else {
                // Asset exists — back-fill only what it lacks to self-bill.
                $patch = [];
                if ($tld !== null && empty($domain->tld)) {
                    $patch['tld'] = $tld;
                }
                if ($plan !== null && empty($domain->product_plan_id)) {
                    $patch['product_plan_id'] = $plan->id;
                }
                if (! $domain->auto_renew) {
                    $patch['auto_renew'] = true;
                }
                if ($patch !== []) {
                    $domain->update($patch);
                    $updated++;
                }
            }

            $cp->delete();
            $converted++;
        }

        return [
            'converted' => $converted,
            'domains_created' => $created,
            'domains_updated' => $updated,
        ];
    }

    /**
     * Find the customer's existing domain for this TLD — matched on the tld
     * column or a domain-name suffix (covers domains stored before the tld
     * column was populated). Null TLD means we can't match safely → create.
     */
    private static function findExistingDomain(int $customerId, ?string $tld): ?Domain
    {
        $query = Domain::where('customer_id', $customerId);

        if ($tld === null) {
            return null;
        }

        return $query->where(function ($q) use ($tld): void {
            $q->where('tld', $tld)
                ->orWhere('domain', 'like', '%'.$tld);
        })->first();
    }

    /**
     * Best-effort domain name when creating from a CP. Prefer the CP label if
     * it already looks like a hostname; otherwise build a unique synthetic
     * name (this branch does not run on prod, where the Domain exists).
     */
    private static function deriveDomainName(CustomerProduct $cp, ?string $tld): string
    {
        $label = trim((string) ($cp->label ?? ''));
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9-]+)+$/i', $label)) {
            $candidate = strtolower($label);
        } else {
            $candidate = 'migrated-domain-cp'.$cp->id.($tld ?? '.invalid');
        }

        // domains.domain is unique — disambiguate if needed.
        $name = $candidate;
        $i = 1;
        while (Domain::where('domain', $name)->exists()) {
            $name = $candidate.'-'.$i;
            $i++;
        }

        return $name;
    }
}
