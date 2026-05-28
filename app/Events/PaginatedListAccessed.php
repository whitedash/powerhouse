<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired whenever a staff user pages a list endpoint. Used by
 * DetectMassExport to flag scraping-style access patterns.
 */
class PaginatedListAccessed
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public string $endpoint,
    ) {}
}
