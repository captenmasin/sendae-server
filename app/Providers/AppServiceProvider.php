<?php

namespace App\Providers;

use App\Services\WorkspaceOwner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(WorkspaceOwner::class);
        Passport::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        URL::forceRootUrl(config('app.url'));
        URL::forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME));
        foreach (['signup' => 5, 'signin' => 5, 'recovery' => 3, 'password-reset' => 5, 'connect' => 6] as $name => $limit) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($limit)->by($request->ip()));
        }
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));
        Passport::tokensCan(['mcp:use' => 'Manage Sendae drafts, media, scheduling and publishing']);
    }
}
