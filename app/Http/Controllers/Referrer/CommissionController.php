<?php

namespace App\Http\Controllers\Referrer;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class CommissionController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Referrer/Commissions');
    }
}
