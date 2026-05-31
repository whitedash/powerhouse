<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-app notification surface for staff users. The bell dropdown is
 * fed by HandleInertiaRequests (latest 15 + unread count); this
 * controller owns the full-page list and the read/delete mutations
 * the dropdown and page both POST to.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->paginate(30)
            ->through(fn ($n): array => [
                'id' => $n->id,
                ...$n->data,
                'read' => $n->read_at !== null,
                'time_ago' => $n->created_at->diffForHumans(),
                'created_at' => $n->created_at->format('d M Y H:i'),
            ]);

        // Opening the full list is an explicit "I've seen these" — clear
        // unread state after building the payload so the page still
        // renders the pre-visit highlight on this load.
        $user->unreadNotifications()->update(['read_at' => now()]);

        return Inertia::render('Internal/Notifications/Index', [
            'notifications' => $notifications,
        ]);
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->where('id', $id)->first()?->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->where('id', $id)->delete();

        return back();
    }
}
