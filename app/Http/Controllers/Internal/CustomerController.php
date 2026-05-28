<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Internal/Customers/Index');
    }

    public function show(int $id): Response
    {
        return Inertia::render('Internal/Customers/Show', ['id' => $id]);
    }
}
