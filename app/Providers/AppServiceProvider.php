<?php

namespace App\Providers;

use App\Http\Middleware\CheckProductSuspension;
use App\Http\Middleware\RequirePkce;
use App\Listeners\DetectMassExport;
use App\Listeners\LogSecurityEvent;
use App\Models\BillingEntity;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Policies\BillingEntityPolicy;
use App\Policies\CommissionLedgerPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\ProductPolicy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->registerPolicies();
        $this->configurePassport();
        $this->configureEloquent();
        $this->registerRateLimiters();
        $this->registerSlowQueryListener();
        $this->registerSecurityEventListeners();
        $this->enforcePkceOnAuthorize();
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
    }

    private function registerPolicies(): void
    {
        Gate::policy(BillingEntity::class, BillingEntityPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(CommissionLedger::class, CommissionLedgerPolicy::class);
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
