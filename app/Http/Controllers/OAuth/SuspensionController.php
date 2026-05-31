<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gates the OAuth authorize flow: if the customer's subscription for the
 * requesting product is suspended, they see a branded "Account suspended"
 * page (with their outstanding balance) instead of the consent screen.
 *
 * The check is wired in via CheckProductSuspension middleware on the
 * passport authorize route (see AppServiceProvider) — suspensionResponse()
 * returns the page when suspended, or null to let Passport proceed. The
 * direct GET /oauth/suspended route uses show() for deep-links.
 */
class SuspensionController extends Controller
{
    /**
     * Build the suspension page when the portal user has a suspended
     * product matching the requesting OAuth client; null otherwise.
     * Used by the authorize-route middleware.
     */
    public function suspensionResponse(Request $request): ?Response
    {
        $user = auth('portal')->user();
        if ($user === null) {
            return null;
        }

        $customerId = $user->customer_id ?? $user->id;
        $slug = $this->clientToSlug((string) $request->get('client_id'));

        $suspended = CustomerProduct::where('customer_id', $customerId)
            ->where('status', 'suspended')
            ->whereHas('product', fn ($q) => $q->where('slug', 'like', $slug.'%'))
            ->with([
                'product:id,name,slug,icon_colour',
                'productPlan:id,name',
            ])
            ->first();

        if ($suspended === null) {
            return null;
        }

        return $this->renderPage($customerId, $suspended);
    }

    /**
     * Direct access to /oauth/suspended. Shows the most relevant
     * suspended product, or bounces to the portal dashboard when the
     * customer has no suspended subscription.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $user = auth('portal')->user();
        $customerId = $user->customer_id ?? $user->id;

        $suspended = CustomerProduct::where('customer_id', $customerId)
            ->where('status', 'suspended')
            ->with([
                'product:id,name,slug,icon_colour',
                'productPlan:id,name',
            ])
            ->first();

        if ($suspended === null) {
            return redirect()->route('portal.dashboard');
        }

        return $this->renderPage((int) $customerId, $suspended);
    }

    private function renderPage(int $customerId, CustomerProduct $suspended): Response
    {
        $invoices = Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['overdue', 'sent', 'partially_paid'])
            ->orderBy('due_date')
            ->get(['id', 'number', 'total', 'amount_paid', 'due_date', 'status']);

        $totalOutstanding = (float) $invoices->sum(
            fn (Invoice $inv): float => (float) $inv->total - (float) ($inv->amount_paid ?? 0)
        );

        return Inertia::render('Public/Suspended', [
            'product' => [
                'id' => $suspended->product?->id,
                'name' => $suspended->product?->name,
                'slug' => $suspended->product?->slug,
                'icon_colour' => $suspended->product?->icon_colour,
            ],
            'plan' => $suspended->productPlan?->name,
            'suspension_reason' => $suspended->suspension_reason,
            'invoices' => $invoices->map(fn (Invoice $inv): array => [
                'id' => $inv->id,
                'number' => $inv->number,
                'total' => (float) $inv->total,
                'amount_paid' => (float) ($inv->amount_paid ?? 0),
                'outstanding' => (float) $inv->total - (float) ($inv->amount_paid ?? 0),
                'due_date' => $inv->due_date?->format('d M Y'),
                'days_overdue' => $inv->due_date !== null && $inv->due_date->isPast()
                    ? (int) $inv->due_date->diffInDays(now())
                    : 0,
                'status' => $inv->status,
            ])->values()->all(),
            'total_outstanding' => $totalOutstanding,
            'support_email' => config('mail.from.address'),
            // Flipped on once the Stripe sprint ships the pay button.
            'stripe_enabled' => false,
        ]);
    }

    /**
     * Map a Passport client UUID to its product slug. IDs come from
     * config so staging/prod can override; unknown clients resolve to
     * 'unknown' (which matches no product).
     */
    private function clientToSlug(string $clientId): string
    {
        return match ($clientId) {
            config('services.oauth_clients.maavelus_id') => 'maavelus',
            config('services.oauth_clients.myorderpad_id') => 'myorderpad',
            default => 'unknown',
        };
    }
}
