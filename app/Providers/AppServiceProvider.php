<?php

namespace App\Providers;

use App\Http\Middleware\RequirePkce;
use App\Listeners\DetectMassExport;
use App\Listeners\LogSecurityEvent;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\Invoice;
use App\Policies\CommissionLedgerPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\InvoicePolicy;
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
        $route = $this->app['router']->getRoutes()->getByName('passport.authorizations.authorize');

        if ($route) {
            $route->middleware(RequirePkce::class);
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
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(CommissionLedger::class, CommissionLedgerPolicy::class);
    }

    private function configurePassport(): void
    {
        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));
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
