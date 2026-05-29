<?php

namespace App\Http\Controllers\Referrer;

use App\Http\Controllers\Controller;
use App\Models\CommissionLedger;
use App\Models\CustomerReferral;
use App\Models\Referrer;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Partner-facing landing. Each KPI / list is scoped to the
 * referrer row tied to the authenticated user — never trust any
 * id from the request.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $user = Auth::user();
        $referrer = Referrer::where('user_id', $user->id)->firstOrFail();

        $summary = [
            'customer_count' => CustomerReferral::where('referrer_id', $referrer->id)->count(),
            'pending_commission' => (float) CommissionLedger::where('referrer_id', $referrer->id)
                ->where('status', 'pending')
                ->sum('commission_amount'),
            'approved_commission' => (float) CommissionLedger::where('referrer_id', $referrer->id)
                ->where('status', 'approved')
                ->sum('commission_amount'),
            'paid_this_year' => (float) CommissionLedger::where('referrer_id', $referrer->id)
                ->where('status', 'paid')
                ->whereYear('paid_at', now()->year)
                ->sum('commission_amount'),
            'all_time_paid' => (float) CommissionLedger::where('referrer_id', $referrer->id)
                ->where('status', 'paid')
                ->sum('commission_amount'),
        ];

        $recentCommissions = CommissionLedger::where('referrer_id', $referrer->id)
            ->with(['customer:id,name', 'product:id,name,icon_colour'])
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn (CommissionLedger $c): array => [
                'id' => $c->id,
                'customer_name' => $c->customer ? $c->customer->name : 'Unknown',
                'product_name' => $c->product ? $c->product->name : 'Custom',
                'product_colour' => $c->product?->icon_colour,
                'amount' => round((float) $c->commission_amount, 2),
                'status' => $c->status,
                'trigger_type' => $c->trigger_type,
                'created_at' => $c->created_at?->diffForHumans(),
            ])
            ->all();

        $recentCustomers = CustomerReferral::where('referrer_id', $referrer->id)
            ->with('customer:id,name,city,created_at')
            ->orderByDesc('attributed_at')
            ->take(5)
            ->get()
            ->map(fn (CustomerReferral $r): array => [
                'customer_id' => $r->customer_id,
                'name' => $r->customer ? $r->customer->name : 'Unknown',
                'city' => $r->customer?->city,
                'joined' => $r->customer?->created_at?->diffForHumans(),
            ])
            ->all();

        return Inertia::render('Referrer/Dashboard', [
            'referrer' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'summary' => $summary,
            'recent_commissions' => $recentCommissions,
            'recent_customers' => $recentCustomers,
        ]);
    }
}
