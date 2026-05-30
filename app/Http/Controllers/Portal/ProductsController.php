<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CustomerProduct;
use App\Models\PortalUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Lightweight JSON endpoint listing the active products the logged-in
 * portal user can reach. Mirrors the dashboard's active_products
 * payload but without the full subscription billing context — used
 * by the layout-level "switcher" widget (TBD) and by the
 * /portal/products fallback page if we ever need one.
 */
class ProductsController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var PortalUser $portalUser */
        $portalUser = Auth::guard('portal')->user();

        $products = CustomerProduct::query()
            ->where('customer_id', $portalUser->customer_id)
            ->whereIn('status', ['active', 'trial'])
            ->with('product:id,name,slug,icon_colour')
            ->get()
            ->map(fn (CustomerProduct $cp): array => [
                'id' => $cp->id,
                'name' => $cp->product?->name,
                'slug' => $cp->product?->slug,
                'status' => $cp->status,
                'icon_colour' => $cp->product?->icon_colour,
            ])
            ->filter(fn (array $p): bool => $p['slug'] !== null)
            ->values();

        return response()->json([
            'customer_id' => $portalUser->customer_id,
            'products' => $products,
        ]);
    }
}
