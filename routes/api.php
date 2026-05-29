<?php

use App\Http\Controllers\Api\PricingController;
use Illuminate\Support\Facades\Route;

// Public pricing endpoint. Consumed by product marketing sites — no
// auth required, only active+public plans are exposed. CORS is
// scoped to the three first-party origins in config/cors.php.
Route::get('/pricing/{slug}', PricingController::class)->name('api.pricing');
