<?php

namespace App\Providers;

use App\Http\Middleware\CheckProductSuspension;
use App\Http\Middleware\RequirePkce;
use App\Listeners\DetectMassExport;
use App\Listeners\LogSecurityEvent;
use App\Models\BillingEntity;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\FormTheme;
use App\Models\Invoice;
use App\Models\Person;
use App\Models\Product;
use App\Models\User;
use App\Policies\BillingEntityPolicy;
use App\Policies\CommissionLedgerPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\FormThemePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PersonPolicy;
use App\Policies\ProductPolicy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->enforceProductionSecurity();
        $this->registerPolicies();
        $this->configurePassport();
        $this->configureEloquent();
        $this->registerRateLimiters();
        $this->registerSlowQueryListener();
        $this->registerSecurityEventListeners();
        $this->registerQueueFailureHandler();
        $this->enforcePkceOnAuthorize();
    }

    /**
     * Production hardening that has to happen at boot, before any request
     * is served:
     *   - Force the https scheme so every generated URL (Stripe success/
     *     cancel/return URLs, password-reset links, signed routes) and the
     *     session/CSRF cookies are emitted as secure behind a TLS proxy.
     *
     * NOTE: this method must NEVER throw. It runs inside every provider
     * boot — i.e. on every web request (including the /up health check and
     * every other route) AND during the deploy pipeline's `artisan
     * config:cache`, `route:cache`, `view:cache` and composer's
     * `package:discover`. A throw here is catastrophic: the framework fails
     * to boot before the HTTP exception handler / logger is fully online, so
     * EVERY request — health checks included — returns an empty 500 with no
     * Laravel log entry, and the deploy's cache-warming commands abort
     * leaving no compiled config/routes behind. The "fail closed on a test
     * Stripe key" guard that used to live here did exactly that; it now lives
     * at the payment boundary (App\Services\StripeService::configureStripe),
     * which is the only place a test key can actually move (or fail to move)
     * money. A misconfigured key now breaks checkout, not the whole site.
     */
    private function enforceProductionSecurity(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        URL::forceScheme('https');

        // Surface the misconfiguration loudly (this reaches Sentry via the
        // logging breadcrumbs/handler) without taking the application down.
        if (str_starts_with((string) config('services.stripe.secret'), 'sk_test_')) {
            Log::critical(
                'A Stripe TEST key (sk_test_) is configured in production. '
                .'Live checkout is disabled until a live key is set — see StripeService.'
            );
        }
    }

    /**
     * A queued job that exhausts its retries dies silently otherwise — the
     * failure is only stored in failed_jobs. For a queue that settles
     * invoices, dispatches reinstatements and delivers webhooks, that is a
     * blind spot. Log it at critical (which routes to Sentry once wired) so
     * it surfaces in alerting.
     */
    private function registerQueueFailureHandler(): void
    {
        Queue::failing(function (JobFailed $event): void {
            Log::critical('Queue job failed', [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveName(),
                'exception' => $event->exception->getMessage(),
            ]);

            // A caught job failure is never an "unhandled" exception, so the
            // bootstrap Integration::handles() hook won't see it — send it to
            // Sentry explicitly so alerting fires.
            if (function_exists('Sentry\captureException')) {
                \Sentry\captureException($event->exception);
            }
        });
    }

    private function enforcePkceOnAuthorize(): void
    {
        // Passport registers /oauth/authorize in its own service provider,
        // which boots before AppServiceProvider. We walk the resolved route
        // collection and attach RequirePkce to the named Passport authorize
        // route. Implicit grant is NOT enabled (no Passport::enableImplicitGrant()).
        //
        // CheckProductSuspension rides the same route: a suspended customer
        // is shown the branded suspension page instead of the consent screen.
        // It returns $next($request) untouched when not suspended, so the
        // normal OAuth handshake is never disturbed.
        $route = $this->app['router']->getRoutes()->getByName('passport.authorizations.authorize');

        if ($route) {
            $route->middleware(RequirePkce::class);
            $route->middleware(CheckProductSuspension::class);
        }
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('staff-login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            $key = $email.'|'.$request->ip();

            return Limit::perMinutes(15, 5)
                ->by($key)
                ->response(function (Request $request, array $headers) {
                    $retryAfter = (int) ($headers['Retry-After'] ?? 900);
                    $minutes = max(1, (int) ceil($retryAfter / 60));

                    return back()
                        ->withInput($request->only('email'))
                        ->withErrors([
                            'email' => "Too many attempts. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
                        ]);
                });
        });

        // Portal login + password-reset share the same shape as staff-login:
        // keyed on email|ip so one attacker can't lock out an unrelated user,
        // and the throttle is the only brute-force backstop on the portal
        // guard (Portal\AuthController does no in-controller limiting).
        RateLimiter::for('portal-login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinutes(15, 5)
                ->by($email.'|'.$request->ip())
                ->response(function (Request $request, array $headers) {
                    $retryAfter = (int) ($headers['Retry-After'] ?? 900);
                    $minutes = max(1, (int) ceil($retryAfter / 60));

                    return back()
                        ->withInput($request->only('email'))
                        ->withErrors([
                            'email' => "Too many attempts. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
                        ]);
                });
        });
    }

    private function registerPolicies(): void
    {
        // Phase 3a: super_admin bypasses every gate/policy check. Returns
        // true ONLY for super_admin; null (NOT false) for everyone else, so
        // non-super-admins fall through to the real permission/policy logic
        // (returning false here would blanket-deny them). First Gate::before
        // in the app, scoped to super_admin alone.
        Gate::before(fn ($user, string $ability) => $user instanceof User && $user->isSuperAdmin() ? true : null);

        Gate::policy(BillingEntity::class, BillingEntityPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(CommissionLedger::class, CommissionLedgerPolicy::class);
        Gate::policy(Person::class, PersonPolicy::class);
        Gate::policy(FormTheme::class, FormThemePolicy::class);

        // Deployment maintenance tools (run migrations / clear caches) — no
        // model, so a Gate ability rather than a policy. Phase 3a: gated by
        // the deployment.run permission (super_admin bypasses via Gate::before
        // above; the deployment routes also carry permission:deployment.run).
        Gate::define('manage-deployment', fn (User $user): bool => $user->hasPermissionTo('deployment.run'));
    }

    private function configurePassport(): void
    {
        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));

        // OAuth scopes — the contract between Powerhouse (IdP) and
        // the product apps that consume our tokens. Consumer apps
        // request the scopes they need at /oauth/authorize; the
        // branded consent screen lists them in plain language.
        //
        // Conventions:
        //   profile             — basic identity (always granted)
        //   portal              — the customer's own Powerhouse portal
        //   {product_slug}      — access to a specific consumer app;
        //                         consumer apps verify their scope
        //                         is in the token via /oauth/userinfo
        Passport::tokensCan([
            'profile' => 'View profile info',
            'portal' => 'Access customer portal',
            'maavelus' => 'Access Maavelus restaurant control',
            'myorderpad' => 'Access MyOrderPad',
            'whitedash_portal' => 'Access Whitedash client portal',
        ]);

        // Default scope when none requested — keeps tokens minimal
        // by default. A consumer app that needs product access has
        // to ask for it explicitly in the authorize URL.
        Passport::defaultScopes(['profile']);

        // Replace Passport's default authorize view with the branded
        // Powerhouse one. Blade still receives Passport's parameters
        // (client, user, scopes, request, authToken) so the form
        // submits cleanly to POST /oauth/authorize and DELETE /oauth/authorize.
        Passport::authorizationView('oauth.authorize');
    }

    private function configureEloquent(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
    }

    private function registerSlowQueryListener(): void
    {
        if (! config('app.debug')) {
            return;
        }

        DB::listen(function ($query) {
            if ($query->time > 1000) {
                Log::warning('Slow query detected', [
                    'sql' => $query->sql,
                    'time' => $query->time.'ms',
                ]);
            }
        });
    }

    private function registerSecurityEventListeners(): void
    {
        // LogSecurityEvent has multiple non-`handle` methods, so explicit
        // listener mapping is required. DetectMassExport's `handle()` is
        // auto-discovered by Laravel via its typed event parameter — adding
        // it here would register a second listener and double-count.
        Event::listen(Login::class, [LogSecurityEvent::class, 'onLogin']);
        Event::listen(Failed::class, [LogSecurityEvent::class, 'onFailed']);
        Event::listen(Logout::class, [LogSecurityEvent::class, 'onLogout']);
        Event::listen(PasswordReset::class, [LogSecurityEvent::class, 'onPasswordReset']);
    }
}
