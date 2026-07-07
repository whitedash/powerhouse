<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\PlanCheckoutAttempt;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Abandoned Plans-widget checkouts (Settings → Products area). Read-only
 * v1: plan, visitor, when — the rows the reconciler flagged so staff can
 * decide whether to follow up. No account/invoice ever existed for these
 * (the checkout-init endpoint writes only the tracking row).
 */
class AbandonedCheckoutController extends Controller
{
    public function index(): Response
    {
        $paginator = PlanCheckoutAttempt::where('status', 'abandoned')
            ->with('planPrice.plan.product')
            ->orderByDesc('abandoned_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PlanCheckoutAttempt $a): array => [
                'id' => $a->id,
                'product' => $a->planPrice?->plan?->product?->name,
                'plan' => $a->planPrice?->plan?->name,
                'purchaser_name' => $a->purchaser_name,
                'purchaser_email' => $a->purchaser_email,
                'purchaser_company' => $a->purchaser_company,
                'purchaser_phone' => $a->purchaser_phone,
                'started_at' => $a->started_at->toIso8601String(),
                'abandoned_at' => $a->abandoned_at?->toIso8601String(),
            ]);

        return Inertia::render('Internal/Settings/AbandonedCheckouts', [
            'attempts' => $paginator,
        ]);
    }
}
