<?php

namespace App\Providers;

use DateInterval;
use SplObjectStorage;
use ReflectionProperty;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use App\Services\WorkspaceOwner;
use Illuminate\Support\Facades\URL;
use App\OAuth\LoopbackAuthCodeGrant;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use League\OAuth2\Server\AuthorizationServer;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;

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
        $installed = new SplObjectStorage;
        $this->app->afterResolving(AuthorizationServer::class, function (AuthorizationServer $server) use ($installed): void {
            if ($installed->contains($server) || ! $this->usesAuthCodeGrant($server)) {
                return;
            }

            $installed->attach($server);
            $grant = new LoopbackAuthCodeGrant(
                $this->app->make(AuthCodeRepository::class),
                $this->app->make(RefreshTokenRepository::class),
                new DateInterval('PT10M'),
            );
            $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
            $server->enableGrantType($grant, Passport::tokensExpireIn());
        });
    }

    private function usesAuthCodeGrant(AuthorizationServer $server): bool
    {
        $grants = new ReflectionProperty($server, 'enabledGrantTypes')->getValue($server);

        return isset($grants['authorization_code']);
    }
}
