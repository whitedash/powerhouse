<?php

namespace App\Listeners;

use App\Events\PaginatedListAccessed;
use App\Mail\MassExportDetected;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class DetectMassExport
{
    private const WINDOW_MINUTES = 10;

    private const THRESHOLD = 50;

    public function handle(PaginatedListAccessed $event): void
    {
        $key = "export_count_{$event->userId}";

        // Seed the key with TTL atomically on the first hit (the database
        // cache driver returns false for `increment` on a missing key, so
        // we can't seed via increment + branch like memcached/redis allow).
        Cache::add($key, 0, now()->addMinutes(self::WINDOW_MINUTES));
        $count = (int) Cache::increment($key);

        if ($count !== self::THRESHOLD + 1) {
            return;
        }

        ActivityLog::create([
            'user_id' => $event->userId,
            'user_role' => User::find($event->userId)?->role,
            'action' => 'security.mass_export_detected',
            'entity_type' => 'user',
            'entity_id' => $event->userId,
            'after' => [
                'page_count' => $count,
                'endpoint' => $event->endpoint,
                'window_minutes' => self::WINDOW_MINUTES,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);

        $admin = User::where('role', 'super_admin')->first();
        if ($admin?->email) {
            Mail::to($admin->email)->queue(new MassExportDetected($event, $count));
        }
    }
}
