<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Static public legal pages (privacy, terms, cookies) linked from the
 * PublicLayout footer. Unauthenticated, no state — each method just renders
 * its long-form content page. Copy is maintained in the Vue pages.
 */
class LegalController extends Controller
{
    public function privacy(): Response
    {
        return Inertia::render('Public/Legal/Privacy');
    }

    public function terms(): Response
    {
        return Inertia::render('Public/Legal/Terms');
    }

    public function cookies(): Response
    {
        return Inertia::render('Public/Legal/Cookies');
    }
}
