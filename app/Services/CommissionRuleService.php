<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CommissionRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create/update commission rules from the admin UI. Translates the
 * friendly form inputs (mode + percentage/flat/recurring) into the EXACT
 * type + config contract CommissionService::calculate() consumes, and
 * guards against ambiguous resolution (two overlapping active rules for the
 * same referrer × product).
 *
 * Form → engine contract:
 *   mode=one_off,  rate_kind=percentage → type=one_off_pct, config{percentage}
 *   mode=one_off,  rate_kind=flat       → type=one_off_pct, config{flat_amount}
 *   mode=recurring                      → type=hybrid,      config{recurring_percentage[, recurring_months]}
 * (recurring_tiered is deferred/stubbed and never written here.)
 */
class CommissionRuleService
{
    /**
     * @param  array<string, mixed>  $data  validated StoreCommissionRuleRequest data
     */
    public function create(array $data): CommissionRule
    {
        [$type, $config] = $this->buildTypeAndConfig($data);
        $validFrom = Carbon::parse($data['valid_from']);
        $validUntil = ! empty($data['valid_until']) ? Carbon::parse($data['valid_until']) : null;
        $isActive = (bool) ($data['is_active'] ?? true);

        $this->assertNoOverlap(
            referrerId: $data['referrer_id'] ?? null,
            productId: (int) $data['product_id'],
            validFrom: $validFrom,
            validUntil: $validUntil,
            isActive: $isActive,
        );

        return DB::transaction(function () use ($data, $type, $config, $validFrom, $validUntil, $isActive): CommissionRule {
            $rule = CommissionRule::create([
                'referrer_id' => $data['referrer_id'] ?? null,
                'product_id' => (int) $data['product_id'],
                'type' => $type,
                'config' => $config,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'is_active' => $isActive,
            ]);

            $this->log('commission_rule.created', $rule);

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CommissionRule $rule, array $data): CommissionRule
    {
        [$type, $config] = $this->buildTypeAndConfig($data);
        $validFrom = Carbon::parse($data['valid_from']);
        $validUntil = ! empty($data['valid_until']) ? Carbon::parse($data['valid_until']) : null;
        $isActive = (bool) ($data['is_active'] ?? true);

        $this->assertNoOverlap(
            referrerId: $data['referrer_id'] ?? null,
            productId: (int) $data['product_id'],
            validFrom: $validFrom,
            validUntil: $validUntil,
            isActive: $isActive,
            excludeId: $rule->id,
        );

        return DB::transaction(function () use ($rule, $data, $type, $config, $validFrom, $validUntil, $isActive): CommissionRule {
            $before = $rule->only(['type', 'config', 'valid_from', 'valid_until', 'is_active', 'referrer_id', 'product_id']);

            $rule->update([
                'referrer_id' => $data['referrer_id'] ?? null,
                'product_id' => (int) $data['product_id'],
                'type' => $type,
                'config' => $config,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'is_active' => $isActive,
            ]);

            $this->log('commission_rule.updated', $rule, $before);

            return $rule;
        });
    }

    /**
     * Flip active state. Activating runs the overlap guard so a dormant
     * rule can't be switched on into an ambiguous window.
     */
    public function setActive(CommissionRule $rule, bool $active): CommissionRule
    {
        if ($active) {
            $this->assertNoOverlap(
                referrerId: $rule->referrer_id,
                productId: $rule->product_id,
                validFrom: $rule->valid_from ?? now(),
                validUntil: $rule->valid_until,
                isActive: true,
                excludeId: $rule->id,
            );
        }

        return DB::transaction(function () use ($rule, $active): CommissionRule {
            $before = ['is_active' => $rule->is_active];
            $rule->update(['is_active' => $active]);
            $this->log('commission_rule.'.($active ? 'activated' : 'deactivated'), $rule, $before);

            return $rule;
        });
    }

    /**
     * Translate friendly inputs → [type, config]. The config carries ONLY
     * the keys the calculator reads.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildTypeAndConfig(array $data): array
    {
        if (($data['mode'] ?? null) === 'recurring') {
            $config = ['recurring_percentage' => round((float) $data['recurring_percentage'], 4)];
            // Duration: "months" → N cap; "lifetime" → omit (null = forever).
            if (($data['duration'] ?? 'lifetime') === 'months') {
                $config['recurring_months'] = (int) $data['recurring_months'];
            }

            return ['hybrid', $config];
        }

        // One-off: flat takes precedence over % in the calculator, so we
        // store exactly one of the two.
        if (($data['rate_kind'] ?? 'percentage') === 'flat') {
            return ['one_off_pct', ['flat_amount' => round((float) $data['flat_amount'], 2)]];
        }

        return ['one_off_pct', ['percentage' => round((float) $data['percentage'], 4)]];
    }

    /**
     * Block a second ACTIVE rule for the same (referrer_id, product_id) whose
     * validity window overlaps — the resolver picks one rule by ordering with
     * no DB uniqueness, so overlapping actives would make resolution
     * ambiguous. Inactive rules are exempt (the resolver only reads actives).
     */
    private function assertNoOverlap(
        ?int $referrerId,
        int $productId,
        Carbon $validFrom,
        ?Carbon $validUntil,
        bool $isActive,
        ?int $excludeId = null,
    ): void {
        if (! $isActive) {
            return;
        }

        // Open-ended windows use sentinels for the overlap maths.
        $newEnd = $validUntil ?? Carbon::parse('9999-12-31');

        $clash = CommissionRule::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->when(
                $referrerId === null,
                fn ($q) => $q->whereNull('referrer_id'),
                fn ($q) => $q->where('referrer_id', $referrerId),
            )
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            // overlap: existing.from <= new.end AND (existing.until IS NULL OR existing.until >= new.from)
            ->where('valid_from', '<=', $newEnd)
            ->where(function ($q) use ($validFrom) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $validFrom);
            })
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'valid_from' => 'An active rule already covers this product'
                    .($referrerId === null ? ' (default)' : ' for this referrer')
                    .' over an overlapping period. Deactivate or end-date it first, or adjust the dates — resolution must be unambiguous.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function log(string $action, CommissionRule $rule, ?array $before = null): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'user_role' => auth()->user()?->role,
            'action' => $action,
            'entity_type' => 'commission_rule',
            'entity_id' => $rule->id,
            'before' => $before,
            'after' => [
                'referrer_id' => $rule->referrer_id,
                'product_id' => $rule->product_id,
                'type' => $rule->type,
                'config' => $rule->config,
                'valid_from' => $rule->valid_from?->toDateString(),
                'valid_until' => $rule->valid_until?->toDateString(),
                'is_active' => $rule->is_active,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);
    }
}
