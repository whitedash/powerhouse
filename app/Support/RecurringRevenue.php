<?php

namespace App\Support;

use App\Models\CustomerProduct;
use App\Models\Domain;
use App\Models\Website;

/**
 * Canonical recurring-revenue aggregator (Stage 2).
 *
 * After Stages 1a/1b recurring revenue comes from THREE sources; every MRR/ARR
 * figure must read from here so none of them under-count:
 *
 *   - Services: active CustomerProducts whose plan is NEITHER is_hosting NOR
 *     is_domain. Stray hosting/domain CPs are defensively excluded so they
 *     can't double-count with the asset sources below.
 *   - Hosting:  websites with hosting_status='active' and a plan_price set.
 *   - Domains:  domains with auto_renew=1 and a matched plan (product_plan_id),
 *     priced off that plan's single active tier.
 *
 * "Active arrangement" drives inclusion — NOT auto_invoice — mirroring how CP
 * MRR already counts active CPs regardless of auto_invoice. Monthly amounts are
 * amortised via the existing ProductPlanPrice / CustomerProduct mrr_contribution
 * accessors (annual ÷12, monthly as-is, one-time → 0).
 *
 * Computed in three eager-loaded queries — no N+1.
 *
 * @phpstan-type Bucket array{monthly: float, count: int}
 */
class RecurringRevenue
{
    /**
     * @param  array{services: float, hosting: float, domains: float}  $bySource
     * @param  array<int, float>  $perCustomer  customer_id => monthly
     * @param  array<int, Bucket>  $perPlan  plan_id => {monthly,count}
     * @param  array<int, Bucket>  $perProduct  product_id => {monthly,count}
     * @param  array<int, array<int, true>>  $serviceCustomersByProduct  product_id => set of customer_ids (service CPs only)
     * @param  array<int, float>  $serviceMonthlyByProduct  product_id => services-only monthly
     * @param  array<int, true>  $hostingCustomers  customer_ids with active hosting (website + plan)
     * @param  array<int, true>  $domainCustomers  customer_ids with a plan-matched auto_renew domain
     */
    private function __construct(
        public readonly float $total,
        public readonly array $bySource,
        public readonly array $perCustomer,
        public readonly array $perPlan,
        public readonly array $perProduct,
        public readonly array $serviceCustomersByProduct,
        public readonly array $serviceMonthlyByProduct,
        public readonly array $hostingCustomers,
        public readonly array $domainCustomers,
    ) {}

    public static function compute(): self
    {
        $services = 0.0;
        $hosting = 0.0;
        $domains = 0.0;

        /** @var array<int, float> $perCustomer */
        $perCustomer = [];
        /** @var array<int, array{monthly: float, count: int}> $perPlan */
        $perPlan = [];
        /** @var array<int, array{monthly: float, count: int}> $perProduct */
        $perProduct = [];

        // Per-source customer sets + services-only-per-product monthly. These
        // let a product overview count DISTINCT customers across the three
        // sources (services for that product ∪ all hosting ∪ all domains) and
        // sum MRR as services(product) + hosting + domains — reconciling with
        // bySource — without perProduct's services/hosting blending getting in
        // the way (domains are attributed to their own catalog product).
        /** @var array<int, array<int, true>> $serviceCustomersByProduct */
        $serviceCustomersByProduct = [];
        /** @var array<int, float> $serviceMonthlyByProduct */
        $serviceMonthlyByProduct = [];
        /** @var array<int, true> $hostingCustomers */
        $hostingCustomers = [];
        /** @var array<int, true> $domainCustomers */
        $domainCustomers = [];

        // ── Services ──────────────────────────────────────────────────────
        $cps = CustomerProduct::where('status', 'active')
            ->with(['planPrice', 'productPlan:id,product_id,is_hosting,is_domain'])
            ->get();

        foreach ($cps as $cp) {
            $plan = $cp->productPlan;
            // Defensive: a stray hosting/domain CP is counted under the asset
            // sources, never here — so it can't double-count.
            if ($plan && ($plan->is_hosting || $plan->is_domain)) {
                continue;
            }

            $monthly = (float) $cp->mrr_contribution;
            $services += $monthly;
            $perCustomer[$cp->customer_id] = ($perCustomer[$cp->customer_id] ?? 0.0) + $monthly;
            $serviceCustomersByProduct[$cp->product_id][$cp->customer_id] = true;
            $serviceMonthlyByProduct[$cp->product_id] = ($serviceMonthlyByProduct[$cp->product_id] ?? 0.0) + $monthly;
            if ($cp->plan_id !== null) {
                self::bump($perPlan, $cp->plan_id, $monthly);
            }
            self::bump($perProduct, $cp->product_id, $monthly);
        }

        // ── Hosting (on the website) ──────────────────────────────────────
        $websites = Website::where('hosting_status', 'active')
            ->whereNotNull('plan_price_id')
            ->with(['planPrice', 'plan:id,product_id'])
            ->get();

        foreach ($websites as $website) {
            $price = $website->planPrice;
            if ($price === null) {
                continue;
            }

            $monthly = (float) $price->mrr_contribution;
            $hosting += $monthly;
            $perCustomer[$website->customer_id] = ($perCustomer[$website->customer_id] ?? 0.0) + $monthly;
            $hostingCustomers[$website->customer_id] = true;
            if ($website->plan_id !== null) {
                self::bump($perPlan, $website->plan_id, $monthly);
            }
            if ($website->plan?->product_id !== null) {
                self::bump($perProduct, $website->plan->product_id, $monthly);
            }
        }

        // ── Domains (self-renewing) ───────────────────────────────────────
        $domainRows = Domain::where('auto_renew', true)
            ->whereNotNull('product_plan_id')
            ->with(['plan:id,product_id', 'plan.activePrices'])
            ->get();

        foreach ($domainRows as $domain) {
            $plan = $domain->plan;
            // No plan (deactivated) or no active tier → can't be priced, so it
            // contributes nothing rather than guessing.
            $tier = $plan?->activePrices->first();
            if ($plan === null || $tier === null) {
                continue;
            }

            $monthly = (float) $tier->mrr_contribution;
            $domains += $monthly;
            $perCustomer[$domain->customer_id] = ($perCustomer[$domain->customer_id] ?? 0.0) + $monthly;
            $domainCustomers[$domain->customer_id] = true;
            self::bump($perPlan, (int) $domain->product_plan_id, $monthly);
            self::bump($perProduct, $plan->product_id, $monthly);
        }

        return new self(
            total: round($services + $hosting + $domains, 2),
            bySource: [
                'services' => round($services, 2),
                'hosting' => round($hosting, 2),
                'domains' => round($domains, 2),
            ],
            perCustomer: array_map(fn (float $v): float => round($v, 2), $perCustomer),
            perPlan: self::roundBuckets($perPlan),
            perProduct: self::roundBuckets($perProduct),
            serviceCustomersByProduct: $serviceCustomersByProduct,
            serviceMonthlyByProduct: array_map(fn (float $v): float => round($v, 2), $serviceMonthlyByProduct),
            hostingCustomers: $hostingCustomers,
            domainCustomers: $domainCustomers,
        );
    }

    public function arr(): float
    {
        return round($this->total * 12, 2);
    }

    public function forCustomer(int $customerId): float
    {
        return $this->perCustomer[$customerId] ?? 0.0;
    }

    /**
     * Whether a customer has ANY active billable asset/service — the canonical
     * "Active" definition for the customer list, drawn from the SAME three
     * sources as MRR (active service CP, active hosting website with a plan,
     * auto_renew domain matched to a plan), so "Active" and "MRR" can never
     * disagree. A £0-priced active arrangement still counts: it sets the
     * perCustomer key with a 0.0 amount, so this means "has an active
     * arrangement" (the intended semantics) rather than strictly MRR > 0.
     */
    public function isActiveCustomer(int $customerId): bool
    {
        return array_key_exists($customerId, $this->perCustomer);
    }

    /**
     * All customer ids with an active billable asset/service — lets the list
     * controller compute the active/inactive summary counts from the same
     * source as the per-row badge.
     *
     * @return list<int>
     */
    public function activeCustomerIds(): array
    {
        return array_keys($this->perCustomer);
    }

    public function planMonthly(int $planId): float
    {
        return $this->perPlan[$planId]['monthly'] ?? 0.0;
    }

    public function planCount(int $planId): int
    {
        return $this->perPlan[$planId]['count'] ?? 0;
    }

    public function productMonthly(int $productId): float
    {
        return $this->perProduct[$productId]['monthly'] ?? 0.0;
    }

    public function productCount(int $productId): int
    {
        return $this->perProduct[$productId]['count'] ?? 0;
    }

    /**
     * Distinct customers with an active SERVICE CP for this product (assets
     * excluded — see hostingCustomerIds / domainCustomerIds for those).
     *
     * @return list<int>
     */
    public function serviceCustomerIds(int $productId): array
    {
        return array_keys($this->serviceCustomersByProduct[$productId] ?? []);
    }

    /**
     * Services-only monthly for this product (excludes hosting/domain, unlike
     * productMonthly which folds hosting into the hosting plan's product).
     */
    public function serviceMonthly(int $productId): float
    {
        return $this->serviceMonthlyByProduct[$productId] ?? 0.0;
    }

    /**
     * Distinct customers with active hosting (a website with a plan).
     *
     * @return list<int>
     */
    public function hostingCustomerIds(): array
    {
        return array_keys($this->hostingCustomers);
    }

    /**
     * Distinct customers with a plan-matched auto_renew domain.
     *
     * @return list<int>
     */
    public function domainCustomerIds(): array
    {
        return array_keys($this->domainCustomers);
    }

    /**
     * @param  array<int, array{monthly: float, count: int}>  $bucket
     */
    private static function bump(array &$bucket, int $key, float $monthly): void
    {
        if (! isset($bucket[$key])) {
            $bucket[$key] = ['monthly' => 0.0, 'count' => 0];
        }
        $bucket[$key]['monthly'] += $monthly;
        $bucket[$key]['count']++;
    }

    /**
     * @param  array<int, array{monthly: float, count: int}>  $bucket
     * @return array<int, array{monthly: float, count: int}>
     */
    private static function roundBuckets(array $bucket): array
    {
        foreach ($bucket as $key => $value) {
            $bucket[$key]['monthly'] = round($value['monthly'], 2);
        }

        return $bucket;
    }
}
