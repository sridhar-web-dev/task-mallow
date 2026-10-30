<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateMerchant;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Observers\CustomerObserver;
use App\Observers\MerchantObserver;
use App\Observers\PlanObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Plan::observe(PlanObserver::class);
        Merchant::observe(MerchantObserver::class);
        Customer::observe(CustomerObserver::class);

        // Keyed by merchant, not IP: one merchant may send from many servers,
        // and several may share an IP behind a proxy.
        RateLimiter::for('usage', fn (Request $request) => Limit::perMinute(config('billing.usage.rate_limit_per_minute'))
            ->by('merchant:'.$request->attributes->get(AuthenticateMerchant::REQUEST_ATTRIBUTE)));
    }
}
