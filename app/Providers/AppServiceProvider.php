<?php

namespace App\Providers;

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
        // Get current URL
        // $url = \URL::current();
        // // Check if URL starts with 'https://'
        // if (strpos($url, 'https://') === false) {
        //     // Redirect to HTTPS
        //     \URL::forceScheme('https');
        // }
    }
}
