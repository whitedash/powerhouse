<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Per-user Google Calendar OAuth dance. Each staff member connects
 * their own Google account; tokens land encrypted on their users row.
 * Connect/disconnect only ever touch auth()->user(), so one operator
 * can never read or revoke another's connection.
 */
class GoogleCalendarAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return redirect()->away($this->client()->createAuthUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        // User declined consent on Google's screen.
        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect('/account')->with('error', 'Google Calendar connection cancelled.');
        }

        $token = $this->client()->fetchAccessTokenWithAuthCode($request->string('code')->toString());

        if (isset($token['error']) || ! isset($token['access_token'])) {
            return redirect('/account')->with('error', 'Google Calendar connection failed.');
        }

        $user = $request->user();
        $user->update([
            'google_access_token' => $token['access_token'],
            // Google only returns a refresh_token on the first consent
            // (or when prompt=consent forces it, which we do). Keep any
            // existing one if this round didn't include a fresh token.
            'google_refresh_token' => $token['refresh_token'] ?? $user->google_refresh_token,
            'google_token_expires_at' => Carbon::now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'google_calendar_id' => $user->google_calendar_id ?? 'primary',
            'google_sync_enabled' => true,
        ]);

        $this->log($request, 'user.google_calendar_connected');

        return redirect('/account')->with('success', 'Google Calendar connected.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $request->user()->update([
            'google_access_token' => null,
            'google_refresh_token' => null,
            'google_token_expires_at' => null,
            'google_calendar_id' => null,
            'google_sync_enabled' => false,
        ]);

        $this->log($request, 'user.google_calendar_disconnected');

        return back()->with('success', 'Google Calendar disconnected.');
    }

    /**
     * A client configured for the auth-code flow. prompt=consent forces
     * Google to hand back a refresh_token every time, so a re-connect
     * after a disconnect always restores offline access.
     */
    private function client(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setRedirectUri((string) config('services.google.redirect'));
        $client->addScope(GoogleCalendar::CALENDAR);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    private function log(Request $request, string $action): void
    {
        $user = $request->user();

        ActivityLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'action' => $action,
            'entity_type' => $user::class,
            'entity_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
