<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommissionRuleRequest;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\Referrer;
use App\Services\CommissionRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin CRUD for commission_rules (super_admin; the Settings route group
 * gate + CommissionRulePolicy both enforce it). The form is a faithful
 * front-end to CommissionService::calculate()'s config contract — the
 * service translates friendly inputs into type + config.
 */
class CommissionRuleController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CommissionRule::class);

        $productFilter = $request->integer('product_id') ?: null;
        $referrerFilter = $request->string('referrer_id')->toString() ?: null; // 'default' | id | null
        $activeFilter = $request->string('active')->toString() ?: null;        // '1' | '0' | null

        $rules = CommissionRule::query()
            ->with(['product:id,name', 'referrer.user:id,name'])
            ->when($productFilter, fn ($q, $p) => $q->where('product_id', $p))
            ->when($referrerFilter === 'default', fn ($q) => $q->whereNull('referrer_id'))
            ->when($referrerFilter !== null && $referrerFilter !== 'default', fn ($q) => $q->where('referrer_id', (int) $referrerFilter))
            ->when($activeFilter !== null, fn ($q) => $q->where('is_active', $activeFilter === '1'))
            ->orderByDesc('is_active')
            ->orderBy('product_id')
            ->orderByRaw('referrer_id IS NULL DESC')
            ->orderByDesc('valid_from')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (CommissionRule $r): array => $this->mapRule($r));

        return Inertia::render('Internal/Settings/CommissionRules', [
            'rules' => $rules,
            'products' => Product::orderBy('name')->get(['id', 'name']),
            'referrers' => Referrer::with('user:id,name')
                ->get()
                ->map(function (Referrer $r): array {
                    $name = $r->user?->name;

                    return ['id' => $r->id, 'name' => $name !== null ? $name : 'Referrer #'.$r->id];
                })
                ->sortBy('name')
                ->values(),
            'filters' => [
                'product_id' => $productFilter,
                'referrer_id' => $referrerFilter,
                'active' => $activeFilter,
            ],
        ]);
    }

    public function store(StoreCommissionRuleRequest $request, CommissionRuleService $service): RedirectResponse
    {
        $service->create($request->validated());

        return back()->with('success', 'Commission rule created.');
    }

    public function update(int $id, StoreCommissionRuleRequest $request, CommissionRuleService $service): RedirectResponse
    {
        $rule = CommissionRule::findOrFail($id);
        Gate::authorize('update', $rule);

        $service->update($rule, $request->validated());

        return back()->with('success', 'Commission rule updated.');
    }

    public function toggleActive(int $id, CommissionRuleService $service): RedirectResponse
    {
        $rule = CommissionRule::findOrFail($id);
        Gate::authorize('update', $rule);

        $service->setActive($rule, ! $rule->is_active);

        return back()->with('success', 'Commission rule '.($rule->is_active ? 'deactivated' : 'activated').'.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $rule = CommissionRule::findOrFail($id);
        Gate::authorize('delete', $rule);

        // A rule referenced by accrued commissions is part of the audit
        // chain — deactivate instead of deleting.
        if (CommissionLedger::where('rule_id', $rule->id)->exists()) {
            return back()->with('error', 'This rule has accrued commissions and cannot be deleted. Deactivate it instead.');
        }

        $rule->delete();

        return back()->with('success', 'Commission rule deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRule(CommissionRule $r): array
    {
        $config = $r->config ?? [];
        $isRecurring = $r->type === 'hybrid';
        $isFlat = isset($config['flat_amount']) && (float) $config['flat_amount'] > 0;

        $referrerName = null;
        if ($r->referrer_id !== null) {
            $name = $r->referrer?->user?->name;
            $referrerName = $name !== null ? $name : 'Referrer #'.$r->referrer_id;
        }

        return [
            'id' => $r->id,
            'product_id' => $r->product_id,
            'product_name' => $r->product?->name,
            'referrer_id' => $r->referrer_id,
            'referrer_name' => $referrerName,
            'rate_label' => $this->rateLabel($r, $config, $isRecurring, $isFlat),
            // Friendly fields for edit-form prefill (1:1 with the form).
            'mode' => $isRecurring ? 'recurring' : 'one_off',
            'rate_kind' => $isFlat ? 'flat' : 'percentage',
            'percentage' => isset($config['percentage']) ? (float) $config['percentage'] : null,
            'flat_amount' => isset($config['flat_amount']) ? (float) $config['flat_amount'] : null,
            'recurring_percentage' => isset($config['recurring_percentage']) ? (float) $config['recurring_percentage'] : null,
            'duration' => isset($config['recurring_months']) ? 'months' : 'lifetime',
            'recurring_months' => isset($config['recurring_months']) ? (int) $config['recurring_months'] : null,
            'valid_from' => $r->valid_from?->toDateString(),
            'valid_until' => $r->valid_until?->toDateString(),
            'is_active' => (bool) $r->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function rateLabel(CommissionRule $r, array $config, bool $isRecurring, bool $isFlat): string
    {
        if ($r->type === 'recurring_tiered') {
            return 'Tiered (unsupported)';
        }

        if ($isFlat) {
            return '£'.number_format((float) $config['flat_amount'], 2).($isRecurring ? ' / period' : ' one-off');
        }

        if ($isRecurring) {
            $pct = (float) ($config['recurring_percentage'] ?? 0);
            $duration = isset($config['recurring_months'])
                ? ((int) $config['recurring_months']).' months'
                : 'lifetime';

            return $this->pct($pct).'% recurring · '.$duration;
        }

        return $this->pct((float) ($config['percentage'] ?? 0)).'% one-off';
    }

    private function pct(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
