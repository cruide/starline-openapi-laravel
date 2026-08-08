<?php namespace StarlineApi;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use StarlineApi\Storage\CacheTokenStorage;

class StarlineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/starline.php', 'starline');

        $this->app->singleton(CacheTokenStorage::class, function (Application $app): CacheTokenStorage {
            $cache = $app['config']['starline.cache'];

            return new CacheTokenStorage(
                $app['cache']->store($cache['store'] ?? null),
                (string) ($cache['prefix'] ?? 'starline'),
                (int) ($cache['ttl'] ?? 86400),
            );
        });

        $this->app->singleton(StarlineClient::class, function (Application $app): StarlineClient {
            return new StarlineClient(
                (array) $app['config']['starline'],
                $app->make(CacheTokenStorage::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/starline.php' => config_path('starline.php'),
            ], 'starline-config');
        }
    }
}