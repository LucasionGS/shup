<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        // TLS is terminated by the reverse proxy in front of the app, so
        // generated URLs must be forced back to https in production rather than
        // inheriting the plain http the container actually speaks.
        if ($this->app->environment('production') && config('app.force_https', true)) {
            URL::forceScheme('https');
        }

        $this->configureRateLimiting();
    }

    /**
     * Share endpoints are public and password gates are checked on GET, so
     * without a limiter a share password can be brute forced for free.
     * Keyed per client address and per share so one noisy share cannot lock
     * out the rest.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('share', function (Request $request) {
            $share = $request->route('shortCode') ?? 'unknown';

            return [
                Limit::perMinute(60)->by('share:' . $request->ip() . ':' . $share),
                Limit::perMinute(300)->by('share-ip:' . $request->ip()),
            ];
        });
    }
}
