<?php

namespace App\Modules\Runtime\Providers;

use Illuminate\Support\ServiceProvider;

final class RuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('App\\Modules\\Runtime\\Application\\Contracts\\RedisRuntimeProbe', 'App\\Modules\\Runtime\\Infrastructure\\RedisRuntimeProbe');
        $this->app->bind(
            'App\\Modules\\Runtime\\Application\\RuntimeHealthService',
            fn ($container) => $container->make('App\\Support\\Runtime\\RuntimeHealthService'),
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
