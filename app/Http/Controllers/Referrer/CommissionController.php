<?php

namespace App\Http\Controllers\Referrer;

use App\Http\Controllers\Controller;
use App\Models\CommissionLedger;
use App\Models\Referrer;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CommissionController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        $referrer = Referrer::where('user_id', $user->id)->firstOrFail();

        $commissions = CommissionLedger::where('referrer_id', $referrer->id)
            ->with(['customer:id,name', 'product:id,name,icon_colour'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (CommissionLedger $c): array => [
                'id' => $c->id,
                'customer_name' => $c->customer ? $c->customer->name : 'Unknown',
                'product_name' => $c->product ? $c->product->name : 'Custom',
                'product_colour' => $c->product?->icon_colour,
                'trigger_type' => $c->trigger_type,
                'gross_amount' => round((float) $c->gross_amount, 2),
                'commission_amount' => round((float) $c->commission_amount, 2),
                'status' => $c->status,
                'period_start' => $c->period_start?->format('j M Y'),
                'period_end' => $c->period_end?->format('j M Y'),
                'approved_at' => $c->approved_at?->format('j M Y'),
                'paid_at' => $c->paid_at?->format('j M Y'),
                'created_at' => $c->created_at?->format('d M Y'),
            ]);

        $totals = [
            'pending' => (float) CommissionLedger::where('referrer_id', $referrer->id)
                ->where('status', 'pending')
                ->sum('commission_amount'),
            'approved' => (float) CommissionLedger::where('referrer_id', $referrer->id)
                ->where('status', 'approved')
                ->sum('commission_amount'),
            'paid' => (float) CommissionLedger::where('referrer_id', $referrer->id)
                ->where('status', 'paid')
                ->sum('commission_amount'),
        ];

        return Inertia::render('Referrer/Commissions', [
            'commissions' => $commissions,
            'totals' => $totals,
        ]);
    }
}
