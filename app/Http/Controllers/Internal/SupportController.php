<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Internal/Support/Index');
    }
}
