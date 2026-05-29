<?php

namespace App\Http\Controllers\Referrer;

use App\Http\Controllers\Controller;
use App\Models\CommissionLedger;
use App\Models\CustomerProduct;
use App\Models\CustomerReferral;
use App\Models\Referrer;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customers a referrer has brought in. Read-only — referrers
 * never get write access to customer records, only visibility into
 * who they've referred and what those customers are worth.
 */
class CustomerController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        $referrer = Referrer::where('user_id', $user->id)->firstOrFail();

        $referrals = CustomerReferral::where('referrer_id', $referrer->id)
            ->with([
                'customer:id,name,city,created_at',
                'customer.customerProducts' => fn ($q) => $q->whereIn('status', ['active', 'trial'])
                    ->with('product:id,name,icon_colour'),
            ])
            ->orderByDesc('attributed_at')
            ->get();

        // Pre-aggregate commission totals per customer in a single query
        // instead of N+1 sums inside the map closure.
        $commissionTotals = CommissionLedger::where('referrer_id', $referrer->id)
            ->whereIn('customer_id', $referrals->pluck('customer_id'))
            ->selectRaw('customer_id, SUM(commission_amount) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        $rows = $referrals->map(fn (CustomerReferral $r): array => [
            'customer_id' => $r->customer_id,
            'name' => $r->customer ? $r->customer->name : 'Unknown',
            'city' => $r->customer?->city,
            'attributed_at' => $r->attributed_at?->format('d M Y'),
            'joined' => $r->customer?->created_at?->format('M Y'),
            'products' => $r->customer
                ? $r->customer->customerProducts->map(fn (CustomerProduct $cp): array => [
                    'name' => $cp->product ? $cp->product->name : 'Custom',
                    'colour' => $cp->product?->icon_colour,
                    'status' => $cp->status,
                ])->values()->all()
                : [],
            'total_commission' => round((float) ($commissionTotals[$r->customer_id] ?? 0), 2),
        ])->all();

        return Inertia::render('Referrer/Customers', [
            'referrals' => $rows,
            'total_customers' => count($rows),
        ]);
    }
}
