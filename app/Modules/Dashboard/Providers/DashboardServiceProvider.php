<?php

namespace App\Modules\Dashboard\Providers;

use Illuminate\Support\ServiceProvider;

final class DashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('App\\Modules\\Dashboard\\Application\\DashboardMetrics');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
