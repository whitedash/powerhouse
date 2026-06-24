<?php

namespace App\Http\Controllers;

use App\Traits\AuthorizesWithPolicy;
use App\Traits\EnforcesScope;

abstract class Controller
{
    use AuthorizesWithPolicy;
    use EnforcesScope;
}
