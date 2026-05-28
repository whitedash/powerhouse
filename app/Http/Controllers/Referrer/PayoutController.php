<?php

namespace App\Http\Controllers\Referrer;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class PayoutController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Referrer/Payouts');
    }
}
