<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of Laravel's framework `migrations` table (id, migration,
 * batch) so the Deployment page can paginate the run history through Eloquent
 * rather than a raw query. `id` auto-increments per run, so `id DESC` is the
 * true run order (newest first).
 *
 * No timestamps — the framework table has only id/migration/batch.
 */
class SchemaMigration extends Model
{
    protected $table = 'migrations';

    public $timestamps = false;

    protected $guarded = [];
}
