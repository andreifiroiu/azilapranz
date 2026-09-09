<?php

namespace App\Providers;

use App\Support\GooglePlaces;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GooglePlaces::class, fn () => GooglePlaces::make());
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

        // azp.analytics_id is the master switch for both the GA4 tag and the
        // cookie banner, and it fails silently in the direction that is hardest
        // to notice: an empty value in production removes measurement, the
        // consent bar and the cookie section of the privacy policy at once,
        // with no error anywhere. Config caching makes it worse — a cache built
        // while the value is empty bakes that in until the next config:cache.
        // One warning turns "analytics quietly vanished weeks ago" into
        // something greppable.
        if ($this->app->isProduction() && blank(config('azp.analytics_id'))) {
            Log::warning('azp.analytics_id is empty in production — the GA4 tag and the cookie banner are both off.');
        }
    }
}
