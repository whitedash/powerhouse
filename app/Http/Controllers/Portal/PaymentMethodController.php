<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\PortalUser;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer-facing saved-card management (Billing P1). Add a card (SetupIntent +
 * Stripe Elements), list, set-default, remove. READ-ONLY of card data in the
 * sense that NO charge ever happens here (P2). Every query is scoped to the
 * portal user's OWN customer; only safe card metadata is exposed.
 */
class PaymentMethodController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Portal/PaymentMethods', [
            'payment_methods' => $this->cardsFor($this->customerId()),
        ]);
    }

    /**
     * Mint a SetupIntent client_secret for the add-a-card form. Scoped to the
     * portal user's own customer (ensures/creates their Stripe Customer).
     */
    public function setupIntent(StripeService $stripe): JsonResponse
    {
        $portalUser = $this->portalUser();

        return response()->json([
            'client_secret' => $stripe->createSetupIntent($portalUser->customer),
        ]);
    }

    /**
     * Persist a card confirmed client-side. The pm id is validated against the
     * customer's own Stripe Customer inside the service.
     */
    public function store(Request $request, StripeService $stripe): RedirectResponse
    {
        $data = $request->validate([
            'payment_method_id' => ['required', 'string', 'max:100'],
        ]);

        $portalUser = $this->portalUser();

        try {
            $stripe->recordPaymentMethodFromStripe($portalUser->customer, $data['payment_method_id']);
        } catch (\Throwable $e) {
            return back()->withErrors(['payment_method' => 'We could not save that card. Please try again.']);
        }

        return back()->with('success', 'Card saved.');
    }

    public function setDefault(int $id): RedirectResponse
    {
        $customerId = $this->customerId();

        // Scope the lookup to the portal user's customer so a crafted id can
        // never touch another customer's card.
        $method = PaymentMethod::where('customer_id', $customerId)
            ->where('status', 'active')
            ->findOrFail($id);

        DB::transaction(function () use ($customerId, $method): void {
            PaymentMethod::where('customer_id', $customerId)->update(['is_default' => false]);
            $method->update(['is_default' => true]);
        });

        return back()->with('success', 'Default card updated.');
    }

    public function destroy(int $id, StripeService $stripe): RedirectResponse
    {
        $customerId = $this->customerId();

        $method = PaymentMethod::where('customer_id', $customerId)
            ->where('status', 'active')
            ->findOrFail($id);

        // Best-effort Stripe detach; local removal is what the UI reflects.
        try {
            $stripe->detachPaymentMethod($method->stripe_payment_method_id);
        } catch (\Throwable) {
            // ignore — proceed to mark removed locally
        }

        DB::transaction(function () use ($customerId, $method): void {
            $wasDefault = $method->is_default;
            $method->update(['status' => 'removed', 'is_default' => false]);

            // Promote another active card to default if we removed the default.
            if ($wasDefault) {
                $next = PaymentMethod::where('customer_id', $customerId)
                    ->where('status', 'active')
                    ->orderByDesc('id')
                    ->first();
                $next?->update(['is_default' => true]);
            }
        });

        return back()->with('success', 'Card removed.');
    }

    private function portalUser(): PortalUser
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        return $portalUser;
    }

    private function customerId(): int
    {
        return (int) $this->portalUser()->customer_id;
    }

    /**
     * Safe card metadata only — brand / last4 / expiry / default. Never the
     * Stripe ids or anything secret.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cardsFor(int $customerId): array
    {
        return PaymentMethod::where('customer_id', $customerId)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PaymentMethod $pm): array => [
                'id' => $pm->id,
                'brand' => $pm->brand,
                'last4' => $pm->last4,
                'exp_month' => $pm->exp_month,
                'exp_year' => $pm->exp_year,
                'is_default' => $pm->is_default,
                'label' => $pm->label,
            ])
            ->all();
    }
}
