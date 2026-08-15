<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;

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
        Gate::define('viewLogViewer', function ($user = null) {
            if (app()->environment('local')) {
                return true;
            }
            if (! $user) {
                return false;
            }
            $allowed = array_filter(array_map('trim', explode(',', (string) env('LOG_VIEWER_ALLOWED_EMAILS', ''))));
            return in_array($user->email, $allowed, true);
        });
        //
    }
}
