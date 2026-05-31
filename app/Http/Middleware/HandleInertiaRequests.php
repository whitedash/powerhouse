<?php

namespace App\Http\Middleware;

use App\Models\Invoice;
use App\Models\PortalUser;
use App\Models\Product;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
                /*
                 * Portal session is a separate guard, so $request->user()
                 * never resolves it. PortalLayout.vue needs the customer
                 * name for the topnav and the user pill, so we share it
                 * here whenever the portal guard is checked-in.
                 */
                'portal_user' => function (): ?array {
                    $u = Auth::guard('portal')->user();
                    if (! $u instanceof PortalUser) {
                        return null;
                    }
                    $u->loadMissing('customer:id,name,city');

                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'customer' => $u->customer ? [
                            'id' => $u->customer->id,
                            'name' => $u->customer->name,
                            'city' => $u->customer->city,
                        ] : null,
                    ];
                },
            ],
            /*
             * Preview-mode flags surface a banner on the portal +
             * referrer layouts so the operator never forgets they're
             * reading as someone else. Booleans only — never expose
             * the admin's id to the customer-facing surface.
             */
            'portal_preview_mode' => fn (): bool => (bool) $request->session()->get('portal_preview_mode', false),
            'referrer_preview_mode' => fn (): bool => (bool) $request->session()->get('referrer_preview_mode', false),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                /*
                 * Portal invitation flow flashes a temp password back to staff
                 * exactly once. The shape lives in CustomerController@inviteToPortal —
                 * {email, password, message}. Surface it here so Customers/Show.vue
                 * can pop a one-time modal without polluting the success channel.
                 */
                'portal_invite' => fn () => $request->session()->get('portal_invite'),
                /*
                 * Temp-password handoff for the Add-referrer + Reset-password
                 * flows. Same one-shot semantics as portal_invite — the
                 * Referrers page shows a credentials card once, then the
                 * flash clears on the next request.
                 */
                'temp_password' => fn () => $request->session()->get('temp_password'),
                'temp_password_name' => fn () => $request->session()->get('temp_password_name'),
                'temp_password_email' => fn () => $request->session()->get('temp_password_email'),
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
                            default => '/products/'.$p->slug,
                        },
                    ])
                    ->all())
                : [],
            // In-app notifications for the bell dropdown — latest 15 for
            // the staff (web-guard) user. Not cached: it's per-user and
            // must reflect reads/new arrivals immediately. Portal requests
            // resolve no web user, so this stays empty there.
            'notifications' => fn () => $request->user()
                ? $request->user()->notifications()->latest()->take(15)->get()
                    ->map(fn ($n): array => [
                        'id' => $n->id,
                        'type' => $n->data['type'] ?? '',
                        'title' => $n->data['title'] ?? '',
                        'message' => $n->data['message'] ?? '',
                        'url' => $n->data['url'] ?? null,
                        'icon' => $n->data['icon'] ?? 'ti-bell',
                        'colour' => $n->data['colour'] ?? 'var(--accent)',
                        'read' => $n->read_at !== null,
                        'time_ago' => $n->created_at->diffForHumans(),
                    ])
                    ->all()
                : [],
            'unread_count' => fn (): int => $request->user()
                ? $request->user()->unreadNotifications()->count()
                : 0,
        ]);
    }
}
