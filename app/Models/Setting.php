<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;

    protected $fillable = ['key', 'value'];

    /**
     * Read a setting's raw stored value (string), falling back to the
     * given default when the key is absent. Values are stored as plain
     * strings ('true'/'false' for booleans, numeric strings for ints);
     * callers cast as needed — the encode side lives in setValue().
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $raw = static::query()->where('key', $key)->value('value');

        return $raw ?? $default;
    }

    /**
     * Write a setting, encoding booleans to the 'true'/'false' string
     * form the rest of the codebase reads back.
     */
    public static function setValue(string $key, mixed $value): void
    {
        $encoded = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        static::updateOrCreate(['key' => $key], ['value' => $encoded]);
    }
}
