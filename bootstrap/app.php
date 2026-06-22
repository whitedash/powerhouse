<?php

use App\Http\Middleware\EnsurePortalDataOwnership;
use App\Http\Middleware\EnsurePortalUser;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\FormCors;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfPortalAuthenticated;
use App\Http\Middleware\RedirectIfReferrer;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyFormWebhookSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Spatie\Permission\Middleware\PermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([
            SecurityHeaders::class,
            SanitizeInput::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Unauthenticated guests get bounced to the right login.
        //
        // CRITICAL: this callback REPLACES Laravel's default
        // route('login') fallback once registered — returning null
        // here makes the exception handler emit an empty 401 (see
        // vendor/laravel/framework/.../Foundation/Exceptions/Handler.php
        // line 790-794) instead of redirecting. So every branch
        // must return a concrete URL, never null.
        //
        // OAuth + portal paths land on /portal/login (customer
        // session). Everything else — staff dashboard, admin, etc.
        // — falls back to /login (the named 'login' route on the
        // staff side).
        $middleware->redirectGuestsTo(function (Request $request): string {
            if ($request->is('oauth/*') || $request->is('portal/*')) {
                return route('portal.login');
            }

            return route('login');
        });

        // Cross-origin public POST endpoints can't carry a CSRF
        // token — the embed script lives on a third-party site
        // and webhook senders (Zapier, Make, custom integrations)
        // don't browse our pages first. Authentication for these
        // is done via:
        //   - form submit: honeypot + per-IP rate limit
        //   - form webhook: HMAC signature (VerifyFormWebhookSignature)
        $middleware->validateCsrfTokens(except: [
            'forms/*/submit',
            'forms/*/draft*',
            'webhooks/*',
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            // Phase 3a: Spatie's permission middleware under a NON-colliding
            // alias. 'role' stays EnsureRole — the coarse "is an internal
            // operator" gate (the outer role:super_admin,staff group); 'permission'
            // gates the capability sub-groups that were role:super_admin.
            'permission' => PermissionMiddleware::class,
            'portal_auth' => EnsurePortalUser::class,
            'auth.portal' => EnsurePortalUser::class,
            'portal_owns' => EnsurePortalDataOwnership::class,
            'portal_guest' => RedirectIfPortalAuthenticated::class,
            'block_referrer' => RedirectIfReferrer::class,
            'form.webhook' => VerifyFormWebhookSignature::class,
            'forms.cors' => FormCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report every unhandled exception to Sentry. The package ships a
        // config and the prod env template carries SENTRY_*, but nothing
        // registered the handler — without this line no exception ever
        // reached Sentry in production.
        Integration::handles($exceptions);

        // JSON-render the auth exception for any request that's
        // consuming an OAuth token. Without this, an unauthenticated
        // /oauth/userinfo redirects to a login URL — fine for browsers,
        // wrong for consumer apps that need a 401 back. The api/*
        // prefix keeps the existing behaviour for the API route group.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('oauth/userinfo')
                || $request->is('oauth/products')
                || $request->expectsJson(),
        );
    })->create();
