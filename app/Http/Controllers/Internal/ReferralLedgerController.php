<?php

namespace App\Http\Controllers\Internal;

use App\Enums\AttributionSource;
use App\Enums\ProductKey;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomerReferral;
use App\Models\Referrer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff-facing referral attribution ledger — one row per
 * customer_referrals record, filterable by referrer / source / product.
 * Read-only (no id-accepting methods); gated by the internal group's
 * role middleware plus the Company viewAny policy, matching the existing
 * referrer admin surface.
 */
class ReferralLedgerController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Company::class);

        $filters = [
            'referrer_id' => $request->integer('referrer_id') ?: null,
            'source' => in_array($request->query('source'), AttributionSource::values(), true)
                ? $request->query('source') : null,
            'product' => in_array($request->query('product'), ProductKey::values(), true)
                ? $request->query('product') : null,
        ];

        $referrals = CustomerReferral::query()
            ->with([
                'customer:id,name',
                'referrer.user:id,name',
            ])
            ->when($filters['referrer_id'], fn ($q, $id) => $q->where('referrer_id', $id))
            ->when($filters['source'], fn ($q, $s) => $q->where('source', $s))
            ->when($filters['product'], fn ($q, $p) => $q->where('product', $p))
            ->orderByDesc('attributed_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (CustomerReferral $r): array => [
                'id' => $r->id,
                'customer_id' => $r->customer_id,
                // customer_id / referrer_id are NOT NULL FKs (cascade/restrict)
                // and referrers.user_id is NOT NULL, so these relations are
                // always present — no null fallback needed.
                'customer_name' => $r->customer->name,
                'referrer_id' => $r->referrer_id,
                'referrer_name' => $r->referrer->user->name,
                // source is cast to AttributionSource (non-null), so it's
                // always the enum.
                'source' => $r->source->value,
                'source_label' => $r->source->label(),
                'product' => $r->product,
                'campaign' => $r->campaign,
                'attributed_at' => $r->attributed_at?->toIso8601String(),
            ]);

        return Inertia::render('Internal/Referrals/Index', [
            'referrals' => $referrals,
            'filters' => $filters,
            'referrerOptions' => Referrer::with('user:id,name')
                ->get()
                ->map(fn (Referrer $r): array => ['value' => $r->id, 'label' => $r->user->name])
                ->all(),
            'sourceOptions' => AttributionSource::options(),
            'productOptions' => ProductKey::options(),
        ]);
    }
}
