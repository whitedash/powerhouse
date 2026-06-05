<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBaseArticle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The hub front door at "/". Branches by auth state:
 *   - guest                  → the public marketing landing
 *   - staff / super_admin     → the internal dashboard (/dashboard)
 *   - referrer                → the partner portal
 *   - portal customer         → the customer portal (different guard, handled
 *                               defensively in case a portal session reaches here)
 *
 * Authenticated users never see the landing — keeping "/" useful as both the
 * public entry point and the "take me home" link.
 */
class LandingController extends Controller
{
    public function __invoke(): Response|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->to(
                Auth::guard('web')->user()->role === 'referrer'
                    ? route('referrer.dashboard')
                    : route('internal.dashboard'),
            );
        }

        if (Auth::guard('portal')->check()) {
            return redirect()->route('portal.dashboard');
        }

        // Featured help — top public+published articles by popularity. The
        // landing strip self-hides when this is empty.
        $featured = KnowledgeBaseArticle::query()
            ->where('is_public', true)
            ->where('is_published', true)
            ->orderByDesc('views')
            ->take(6)
            ->get(['title', 'slug', 'category'])
            ->all();

        return Inertia::render('Public/Landing', [
            'featured' => $featured,
        ]);
    }
}
