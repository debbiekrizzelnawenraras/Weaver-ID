<?php

namespace App\Providers;

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
        // 5 login attempts per minute for each email and IP address pair.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(
                strtolower((string) $request->input('email')) . '|' . $request->ip()
            );
        });

        // The public verify endpoint is limited per IP address.
        RateLimiter::for('verify', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Reports are public too, so they are limited harder than scans.
        RateLimiter::for('reports', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });
    }
}
