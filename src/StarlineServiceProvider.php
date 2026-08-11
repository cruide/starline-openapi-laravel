<?php namespace Cruide\StarlineLaravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Cruide\StarlineLaravel\Storage\CacheTokenStorage;

class StarlineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/starline.php', 'starline');

        $this->app->singleton(CacheTokenStorage::class, function (Application $app): CacheTokenStorage {
            $cache = $app['config']['starline.cache'];

            return new CacheTokenStorage(
                $app['cache']->store($cache['store'] ?? null),
                (string) ($cache['prefix'] ?? ''),
                (int) ($cache['ttl'] ?? 86400),
            );
        });

        $this->app->singleton(Client::class, function (Application $app): Client {
            return new Client(
                (array) $app['config']['starline'],
                $app->make(CacheTokenStorage::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/starline.php' => config_path('starline.php'),
            ], 'starline-config');
        }
    }
}
