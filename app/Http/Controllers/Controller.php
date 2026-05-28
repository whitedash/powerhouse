<?php

namespace App\Http\Controllers;

use App\Traits\AuthorizesWithPolicy;

abstract class Controller
{
    use AuthorizesWithPolicy;
}
