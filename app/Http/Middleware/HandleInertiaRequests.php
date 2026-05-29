<?php

namespace App\Http\Middleware;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => fn () => $request->user()
                    ? [
                        'id' => $request->user()->id,
                        'name' => $request->user()->name,
                        'email' => $request->user()->email,
                        'role' => $request->user()->role,
                        'avatar_colour' => $request->user()->avatar_colour,
                    ]
                    : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'nav' => fn () => $request->user()
                ? [
                    'invoices_overdue' => Cache::remember(
                        'nav.invoices_overdue',
                        60,
                        fn () => Invoice::where('status', 'overdue')->count(),
                    ),
                    'invoices_outstanding' => Cache::remember(
                        'nav.invoices_outstanding',
                        60,
                        fn () => Invoice::where('status', 'sent')->count(),
                    ),
                    'support_sla_breached' => Cache::remember(
                        'nav.support_sla_breached',
                        60,
                        fn () => SupportTicket::whereNotIn('status', ['resolved', 'closed'])
                            ->whereNotNull('sla_breach_at')
                            ->where('sla_breach_at', '<', now())
                            ->count(),
                    ),
                    'support_open' => Cache::remember(
                        'nav.support_open',
                        60,
                        fn () => SupportTicket::whereNotIn('status', ['resolved', 'closed'])->count(),
                    ),
                ]
                : null,
            // Sidebar Products section — pulled from the DB so deactivating
            // a product in Settings → Products immediately drops it from
            // the nav for everyone. 5-min TTL because product rows change
            // far less often than invoice/ticket badges.
            'nav_products' => fn () => $request->user()
                ? Cache::remember('nav.products', 300, fn () => Product::where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'name', 'slug', 'icon_colour'])
                    ->map(fn (Product $p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'slug' => $p->slug,
                        'icon_colour' => $p->icon_colour,
                        'icon' => match ($p->slug) {
                            'maavelus' => 'tools-kitchen-2',
                            'myorderpad' => 'clipboard-list',
                            'whitedash', 'whitedash_b2b' => 'building-store',
                            'smscube' => 'message-2',
                            default => 'box',
                        },
                        'route' => match ($p->slug) {
                            'maavelus' => '/maavelus/statements',
                            default => '/customers?product='.$p->slug,
                        },
                    ])
                    ->all())
                : [],
        ]);
    }
}
