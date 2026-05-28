<?php

namespace App\Http\Controllers\Internal;

use App\Events\PaginatedListAccessed;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        if ($request->user()) {
            PaginatedListAccessed::dispatch($request->user()->id, $request->path());
        }

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
