<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Per-user Google Calendar bridge. Constructed with the staff member
 * whose tokens drive the connection — so two operators each sync to
 * their own calendar without ever touching each other's data.
 *
 * Every outward call is wrapped in a try/catch: a flaky Google API
 * must never take down a task save or the MyWork calendar load. Read
 * failures degrade to an empty list; write failures log and return
 * null so the caller can carry on without a google_event_id.
 */
class GoogleCalendarService
{
    public function __construct(private readonly User $user) {}

    /**
     * Fetch the user's Google events in [$start, $end], normalised to
     * the same shape MyWork uses for Powerhouse tasks so the frontend
     * can render both from one list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(Carbon $start, Carbon $end): array
    {
        try {
            $service = new GoogleCalendar($this->getClient());

            $events = $service->events->listEvents(
                $this->user->google_calendar_id ?? 'primary',
                [
                    'timeMin' => $start->toRfc3339String(),
                    'timeMax' => $end->toRfc3339String(),
                    'singleEvents' => true,
                    'orderBy' => 'startTime',
                    'maxResults' => 250,
                ],
            );

            return collect($events->getItems())
                ->map(function (GoogleEvent $e): array {
                    // All-day events carry only a date; timed events a
                    // dateTime. getDateTime() returns null for all-day at
                    // runtime even though the library PHPDoc types it as
                    // string — the @var widens it back so the null checks
                    // below are honoured.
                    /** @var string|null $startDt */
                    $startDt = $e->getStart()->getDateTime();
                    /** @var string|null $endDt */
                    $endDt = $e->getEnd()->getDateTime();

                    return [
                        'id' => 'gcal_'.$e->getId(),
                        'source' => 'google',
                        // ?: also covers an empty-string summary.
                        'title' => $e->getSummary() ?: '(No title)',
                        'start' => $startDt ?? $e->getStart()->getDate(),
                        'end' => $endDt ?? $e->getEnd()->getDate(),
                        'allDay' => $startDt === null,
                        'location' => $e->getLocation(),
                        'description' => $e->getDescription(),
                        'colour' => '#4285F4', // Google blue
                        'html_link' => $e->getHtmlLink(),
                        'editable' => false,
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('GCal fetch failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Mirror a Powerhouse task into the user's calendar. Returns the new
     * Google event id (to stamp onto the task) or null on failure.
     */
    public function createEvent(Task $task): ?string
    {
        try {
            $service = new GoogleCalendar($this->getClient());

            $event = new GoogleEvent([
                'summary' => $task->title,
                'description' => $task->description !== null ? strip_tags($task->description) : null,
                'location' => $task->location,
                'start' => $this->startDateTime($task),
                'end' => $this->endDateTime($task),
            ]);

            $created = $service->events->insert(
                $this->user->google_calendar_id ?? 'primary',
                $event,
            );

            return $created->getId();
        } catch (\Throwable $e) {
            Log::warning('GCal create failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Push title/location/schedule changes to an already-mirrored task.
     * No-op when the task was never synced (no google_event_id).
     */
    public function updateEvent(Task $task): void
    {
        if ($task->google_event_id === null) {
            return;
        }

        try {
            $service = new GoogleCalendar($this->getClient());
            $calendarId = $this->user->google_calendar_id ?? 'primary';

            $event = $service->events->get($calendarId, $task->google_event_id);
            $event->setSummary($task->title);
            $event->setLocation($task->location);
            $event->setStart($this->startDateTime($task));
            $event->setEnd($this->endDateTime($task));

            $service->events->update($calendarId, $task->google_event_id, $event);
        } catch (\Throwable $e) {
            Log::warning('GCal update failed: '.$e->getMessage());
        }
    }

    /**
     * Remove a mirrored event (task completed/deleted). Swallows the
     * 404/410 Google returns when the event is already gone.
     */
    public function deleteEvent(string $googleEventId): void
    {
        try {
            $service = new GoogleCalendar($this->getClient());
            $service->events->delete(
                $this->user->google_calendar_id ?? 'primary',
                $googleEventId,
            );
        } catch (\Throwable $e) {
            Log::warning('GCal delete failed: '.$e->getMessage());
        }
    }

    /**
     * Build a configured client, auto-refreshing the access token (and
     * persisting the refreshed token) when it has expired.
     */
    private function getClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));

        $client->setAccessToken([
            'access_token' => $this->user->google_access_token,
            'refresh_token' => $this->user->google_refresh_token,
            'expires_in' => 3600,
            'created' => $this->user->google_token_expires_at?->subSeconds(3600)->getTimestamp()
                ?? Carbon::now()->subSeconds(3600)->getTimestamp(),
        ]);

        if ($client->isAccessTokenExpired() && $this->user->google_refresh_token !== null) {
            $newToken = $client->fetchAccessTokenWithRefreshToken($this->user->google_refresh_token);

            if (! isset($newToken['error']) && isset($newToken['access_token'])) {
                $this->user->update([
                    'google_access_token' => $newToken['access_token'],
                    'google_token_expires_at' => Carbon::now()->addSeconds((int) ($newToken['expires_in'] ?? 3600)),
                ]);
            }
        }

        return $client;
    }

    /**
     * Start slot for a task: a bare date for all-day deadlines, a real
     * dateTime for timed events/meetings.
     */
    private function startDateTime(Task $task): EventDateTime
    {
        if ($task->is_all_day) {
            return new EventDateTime(['date' => $task->due_at?->format('Y-m-d')]);
        }

        return new EventDateTime(['dateTime' => $task->start_at?->toRfc3339String()]);
    }

    /**
     * End slot. All-day events in Google are end-exclusive, so a single
     * day's deadline ends on due_at + 1 day. Timed events fall back to
     * a one-hour block when no explicit end was set.
     */
    private function endDateTime(Task $task): EventDateTime
    {
        if ($task->is_all_day) {
            return new EventDateTime(['date' => $task->due_at?->copy()->addDay()->format('Y-m-d')]);
        }

        $end = $task->end_at ?? $task->start_at?->copy()->addHour();

        return new EventDateTime(['dateTime' => $end?->toRfc3339String()]);
    }
}
