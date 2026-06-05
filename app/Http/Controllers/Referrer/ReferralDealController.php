<?php

namespace App\Http\Controllers\Referrer;

use App\Enums\ReferralStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReferralDealRequest;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Referrer;
use App\Services\DealRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Partner-facing deal registration. Every read/write is scoped to the
 * authenticated referrer (IDOR-safe) — the referrer is resolved from the
 * session user, never from the request.
 */
class ReferralDealController extends Controller
{
    public function index(): Response
    {
        $referrer = $this->referrer();

        $deals = Lead::where('referrer_id', $referrer->id)
            ->whereNotNull('referral_status')
            ->orderByDesc('registered_at')
            ->paginate(20)
            ->through(fn (Lead $l): array => [
                'id' => $l->id,
                'company' => $l->company,
                'contact_name' => $l->name,
                'product' => $l->source_detail,
                'referral_status' => $l->referral_status?->value,
                'referral_status_label' => $l->referral_status?->label(),
                'registered_at' => $l->registered_at?->format('d M Y'),
                'protected_until' => $l->referral_status === ReferralStatus::Approved
                    ? $l->protected_until?->format('d M Y')
                    : null,
                'review_notes' => $l->referral_status === ReferralStatus::Rejected
                    ? $l->review_notes
                    : null,
            ]);

        $selfServeSlug = (string) config('referrals.self_serve_slug', '');
        $shareLink = $referrer->referral_code
            ? rtrim((string) config('app.url'), '/').'/r/'.$referrer->referral_code
            : null;

        return Inertia::render('Referrer/Referrals', [
            'deals' => $deals,
            'products' => Product::where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug'])
                ->map(fn (Product $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'is_self_serve' => $p->slug === $selfServeSlug,
                ])
                ->values(),
            'self_serve' => [
                'slug' => $selfServeSlug,
                'share_link' => $shareLink,
            ],
        ]);
    }

    public function store(StoreReferralDealRequest $request, DealRegistrationService $service): RedirectResponse
    {
        $referrer = $this->referrer();

        // A non-net-new prospect throws ValidationException inside the
        // service → Laravel redirects back with the non-revealing message.
        $service->register($referrer, $request->validated());

        return back()->with('success', 'Deal submitted — pending review. We’ll let you know once it’s approved.');
    }

    /**
     * The authenticated user's referrer row. firstOrFail keeps every query
     * bound to this partner — no id is ever read from the request.
     */
    private function referrer(): Referrer
    {
        return Referrer::where('user_id', Auth::id())->firstOrFail();
    }
}
