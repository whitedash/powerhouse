<?php

namespace App\Services;

use App\Models\WebhookEvent;

/**
 * One record per (source, event_id). A second webhook for the same event
 * short-circuits in the controller — `hasBeenProcessed()` is the gate;
 * `record()` writes the row.
 */
class WebhookIdempotencyService
{
    public function hasBeenProcessed(string $source, string $eventId): bool
    {
        return WebhookEvent::where('source', $source)
            ->where('event_id', $eventId)
            ->exists();
    }

    public function record(string $source, string $eventId, string $eventType, array $payload): WebhookEvent
    {
        return WebhookEvent::create([
            'source' => $source,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => $payload,
        ]);
    }

    public function markProcessed(WebhookEvent $event): void
    {
        $event->forceFill(['processed_at' => now()])->save();
    }
}
