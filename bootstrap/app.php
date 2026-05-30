<?php

use App\Http\Middleware\EnsurePortalDataOwnership;
use App\Http\Middleware\EnsurePortalUser;
use App\Http\Middleware\EnsureRole;
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

        // Unauthenticated guests hitting an auth:portal route get
        // bounced to /portal/login. The default Authenticate
        // middleware reaches for route('login'), which we don't
        // register — without this hook it falls back to '/login'
        // (a 404). The closure runs for every guest redirect so
        // we limit it to oauth + portal paths to avoid accidentally
        // catching staff routes.
        $middleware->redirectGuestsTo(function (Request $request): ?string {
            if ($request->is('oauth/*') || $request->is('portal/*')) {
                return route('portal.login');
            }

            return null;
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
            'webhooks/*',
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'portal_auth' => EnsurePortalUser::class,
            'auth.portal' => EnsurePortalUser::class,
            'portal_owns' => EnsurePortalDataOwnership::class,
            'portal_guest' => RedirectIfPortalAuthenticated::class,
            'block_referrer' => RedirectIfReferrer::class,
            'form.webhook' => VerifyFormWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
