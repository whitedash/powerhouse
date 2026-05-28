<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Internal/Invoices/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Internal/Invoices/Create');
    }

    public function show(int $id): Response
    {
        return Inertia::render('Internal/Invoices/Show', ['id' => $id]);
    }
}
