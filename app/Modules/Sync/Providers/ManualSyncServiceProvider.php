<?php

namespace App\Modules\Sync\Providers;

use Illuminate\Support\ServiceProvider;

final class ManualSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('App\\Modules\\Sync\\Application\\ManualSyncService');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
