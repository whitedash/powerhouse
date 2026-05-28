<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Portal/Products');
    }
}
