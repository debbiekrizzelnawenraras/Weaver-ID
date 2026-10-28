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

        // Changing a password asks for the current one, so guessing it is limited per account.
        RateLimiter::for('change-password', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        // The public verify endpoint is limited per IP address.
        RateLimiter::for('verify', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Association applications are public and write to the database, so they are limited. Every try
        // counts, including ones refused by the form rules, and an office may help several associations
        // from one address, so the limit allows for that.
        RateLimiter::for('applications', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        // Looking up an application needs its reference and email, so guessing is limited per IP address.
        RateLimiter::for('application-status', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Each email costs the server a mail send and lands in someone's inbox, so a code may be asked
        // for only a few times an hour for one address and a limited number of times from one place.
        RateLimiter::for('email-codes', function (Request $request) {
            return [
                Limit::perHour(20)->by('ip|'.$request->ip()),
                Limit::perHour(6)->by('email|'.strtolower(trim((string) $request->input('email')))),
            ];
        });

        RateLimiter::for('password-forgot', function (Request $request) {
            return [
                Limit::perHour(20)->by('ip|'.$request->ip()),
                Limit::perHour(6)->by('email|'.strtolower(trim((string) $request->input('email')))),
            ];
        });

        // Typing the code is the part that can be guessed, so it is limited per address and per place.
        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perHour(20)->by('ip|'.$request->ip()),
                Limit::perHour(10)->by('email|'.strtolower(trim((string) $request->input('email')))),
            ];
        });

        // Reports are public too, so they are limited harder than scans.
        RateLimiter::for('reports', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });
    }
}
