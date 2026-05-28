<?php

namespace App\Http\Middleware;

use App\Models\Invoice;
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
        ]);
    }
}
