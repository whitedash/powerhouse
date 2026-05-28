<?php

namespace App\Http\Controllers\Referrer;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Referrer/Customers');
    }
}
