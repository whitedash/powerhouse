<?php

namespace App\Console\Commands\Concerns;

/**
 * Shared access to the project-root `.powerhouse.json` — the per-developer
 * file that pins a local checkout to one Powerhouse project (project_id,
 * default assignee, active milestone). It is gitignored: each developer
 * keeps their own copy, so we never hard-code a project id in a command.
 */
trait ReadsPowerhouseConfig
{
    /**
     * Read a single key from `.powerhouse.json`. Returns null when the
     * file is missing or the key is unset, so callers can fall back to a
     * --option or fail with a helpful message rather than guessing.
     */
    protected function readPowerhouseJson(string $key): mixed
    {
        $path = base_path('.powerhouse.json');
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            return null;
        }

        return $data[$key] ?? null;
    }
}
