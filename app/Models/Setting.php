<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;

    protected $fillable = ['key', 'value'];
}
