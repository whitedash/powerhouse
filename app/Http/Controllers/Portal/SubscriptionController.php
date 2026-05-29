<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use App\Models\PortalUser;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer-facing subscriptions view. Shows their active/trial subs
 * with cancel + upgrade hooks, plus the catalogue of products they
 * could subscribe to. Self-service signups create a *pending*
 * CustomerProduct that staff approve from the internal app — keeps
 * provisioning intentional while still letting the customer push
 * the request through without an email back-and-forth.
 */
class SubscriptionController extends Controller
{
    public function index(): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();
        $customerId = (int) $portalUser->customer_id;

        $subscriptions = CustomerProduct::where('customer_id', $customerId)
            ->whereIn('status', ['active', 'trial', 'suspended', 'pending'])
            ->with([
                'product:id,name,slug,icon_colour',
                'productPlan:id,name',
                'planPrice:id,plan_id,price,interval_unit,interval_count',
            ])
            ->orderBy('status')
            ->get()
            ->map(fn (CustomerProduct $cp): array => [
                'id' => $cp->id,
                'product_name' => $cp->product?->name,
                'product_slug' => $cp->product?->slug,
                'icon_colour' => $cp->product?->icon_colour,
                'plan_name' => $cp->productPlan ? $cp->productPlan->name : 'Custom',
                'price' => round((float) ($cp->planPrice ? $cp->planPrice->price : ($cp->price_monthly ?? 0)), 2),
                'interval_label' => $cp->interval_label,
                'status' => $cp->status,
                'started_at' => $cp->started_at?->format('j M Y'),
                'trial_ends_at' => $cp->trial_ends_at?->format('j M Y'),
                'next_billing_date' => $cp->next_billing_date?->format('j M Y'),
                'cancels_at' => $cp->cancels_at?->format('j M Y'),
            ])
            ->all();

        // Catalogue: any active product the customer isn't already
        // subscribed to (active/trial/pending — suspended is excluded so
        // a re-activation is offered as a fresh signup).
        $heldProductIds = CustomerProduct::where('customer_id', $customerId)
            ->whereIn('status', ['active', 'trial', 'pending'])
            ->pluck('product_id');

        $available = Product::where('is_active', true)
            ->whereNotIn('id', $heldProductIds)
            ->with(['plans' => fn ($q) => $q->where('is_active', true)
                ->where('is_public', true)
                ->with(['activePrices' => fn ($qq) => $qq->orderBy('sort_order')])])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Product $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'icon_colour' => $p->icon_colour,
                'plans' => $p->plans->map(fn ($plan): array => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'prices' => $plan->activePrices->map(fn ($price): array => [
                        'id' => $price->id,
                        'price' => round((float) $price->price, 2),
                        'interval_label' => $price->interval_label,
                        'is_default' => $price->is_default,
                    ])->values()->all(),
                ])->values()->all(),
            ])
            ->all();

        return Inertia::render('Portal/Subscriptions', [
            'subscriptions' => $subscriptions,
            'available' => $available,
        ]);
    }

    /**
     * Customer-initiated signup. We don't auto-activate — staff must
     * approve. The CustomerProduct is created with status=pending so
     * the staff Provisioning page surfaces it for review.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'plan_id' => ['required', 'integer', 'exists:product_plans,id'],
            'price_id' => ['required', 'integer', 'exists:product_plan_prices,id'],
        ]);

        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();
        $customerId = (int) $portalUser->customer_id;

        // Block duplicates: if there's already an active/trial/pending row
        // for this product, the customer is trying to double-subscribe.
        $existing = CustomerProduct::where('customer_id', $customerId)
            ->where('product_id', $data['product_id'])
            ->whereIn('status', ['active', 'trial', 'pending'])
            ->exists();

        if ($existing) {
            return back()->withErrors([
                'product_id' => 'You already have an active or pending subscription for this product.',
            ]);
        }

        $sub = DB::transaction(function () use ($data, $customerId, $portalUser): CustomerProduct {
            $sub = CustomerProduct::create([
                'customer_id' => $customerId,
                'product_id' => $data['product_id'],
                'plan_id' => $data['plan_id'],
                'plan_price_id' => $data['price_id'],
                'status' => 'pending',
                'started_at' => null,
            ]);

            ActivityLog::create([
                'user_id' => $portalUser->id,
                'user_role' => 'portal',
                'action' => 'subscription.requested',
                'entity_type' => CustomerProduct::class,
                'entity_id' => $sub->id,
                'after' => [
                    'product_id' => $sub->product_id,
                    'plan_id' => $sub->plan_id,
                ],
            ]);

            return $sub;
        });

        return back()->with('success', 'Subscription request submitted — our team will activate it shortly.');
    }

    /**
     * Customer-requested cancellation. We schedule cancel rather
     * than immediately removing access — they keep what they paid for.
     */
    public function cancel(int $id, Request $request): RedirectResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        // Scope the lookup to the portal user's customer so a crafted
        // id can never cancel someone else's subscription.
        $sub = CustomerProduct::where('customer_id', $portalUser->customer_id)
            ->findOrFail($id);

        if (! in_array($sub->status, ['active', 'trial'], true)) {
            return back()->withErrors(['status' => 'Only active or trial subscriptions can be cancelled.']);
        }

        $before = ['status' => $sub->status, 'cancels_at' => $sub->cancels_at?->toIso8601String()];

        // Cancel at end of current paid period when known; otherwise
        // schedule immediate.
        $cancelDate = $sub->next_billing_date ?? now()->toDateString();

        $sub->status = 'cancelled';
        $sub->cancels_at = $cancelDate;
        $sub->cancelled_at = now();
        $sub->save();

        ActivityLog::create([
            'user_id' => $portalUser->id,
            'user_role' => 'portal',
            'action' => 'subscription.cancelled_by_customer',
            'entity_type' => CustomerProduct::class,
            'entity_id' => $sub->id,
            'before' => $before,
            'after' => [
                'status' => $sub->status,
                // cancels_at was just assigned $cancelDate above, so
                // it's guaranteed non-null here — no nullsafe needed.
                'cancels_at' => $sub->cancels_at instanceof Carbon
                    ? $sub->cancels_at->toIso8601String()
                    : (string) $sub->cancels_at,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        $endLabel = $sub->cancels_at instanceof Carbon
            ? $sub->cancels_at->format('j M Y')
            : (string) $sub->cancels_at;

        return back()->with('success', "Subscription cancelled. You'll keep access until {$endLabel}.");
    }
}
